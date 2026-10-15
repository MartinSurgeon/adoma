<?php

namespace App\Http\Requests;

use App\Domain\Documents\Document;
use App\Domain\Documents\DocumentType;
use App\Domain\Gold\CalculationError;
use App\Domain\Gold\FormulaSet;
use App\Domain\Gold\GoldCalculator;
use App\Domain\Gold\GoldFigures;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Stock\LotAllocator;
use App\Domain\Stock\StockLot;
use App\Domain\Support\Money;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** A purchase or a sale of gold: who, when, the weighing, and the price. The route says which. */
class SaveGoldDocumentRequest extends FormRequest
{
    /** What the form's numbers work out to, worked out here and not trusted from the browser. */
    public ?GoldFigures $figures = null;

    public ?Ledger $party = null;

    /** For a sale of chosen lots: which lots, and how much of each. Empty when the oldest gold goes first. */
    public ?array $picks = null;

    public function authorize(): bool
    {
        return $this->user()->can('create', [Document::class, (string) $this->route('type')]);
    }

    /** Only a sale can be of chosen lots. A purchase makes a lot, it does not take from one. */
    public function choosesLots(): bool
    {
        return $this->route('type') === DocumentType::Sale->value && $this->boolean('choose_lots');
    }

    public function rules(): array
    {
        // Chosen lots are already weighed, so the weighing boxes are not used.
        $weighing = $this->choosesLots()
            ? [
                'form' => ['nullable'], 'weight' => ['nullable'],
                'lots' => ['required', 'array', 'min:1', 'max:200'],
                'lots.*.id' => ['required', 'integer'],
                'lots.*.weight' => ['required', 'string', 'max:20'],
            ]
            : [
                'form' => ['required', Rule::in(['raw', 'refined'])],
                'weight' => ['required', 'string', 'max:20'],
            ];

        return $weighing + [
            'date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:today'],
            'ledger_id' => ['required', 'integer'],
            'choose_lots' => ['nullable', 'boolean'],
            'big_weight' => ['nullable', 'string', 'max:20'],
            'water_weight' => [Rule::requiredIf(! $this->choosesLots() && $this->input('form') === 'raw'), 'nullable', 'string', 'max:20'],
            'karat' => [Rule::requiredIf(! $this->choosesLots() && $this->input('form') === 'refined'), 'nullable', 'string', 'max:20'],
            'price' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'karat_override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:300'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Found through the normal scopes: only this branch's ledgers and the shared ones.
                $this->party = Ledger::query()->whereKey($this->integer('ledger_id'))->where('is_active', true)
                    ->whereIn('kind', [LedgerKind::Customer->value, LedgerKind::Supplier->value])->first();

                if ($this->party === null) {
                    $validator->errors()->add('ledger_id', 'Choose a customer or supplier from your list.');
                }

                // The numbers are worked out here, by the same calculator the preview uses.
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    if ($this->choosesLots()) {
                        [$this->figures, $this->picks] = self::calculateLots($this->user(), (array) $this->input('lots'), (string) $this->input('price'));
                    } else {
                        $this->figures = self::calculate($this->user(), $this->input());
                    }
                } catch (CalculationError $error) {
                    $validator->errors()->add($error->field, $error->getMessage());

                    return;
                }

                // Chosen lots were checked against what each lot holds, so there is no second stock check.
                if ($this->choosesLots()) {
                    return;
                }

                // A karat above 24 an admin allowed needs a reason, which is kept with the gold line.
                if ($this->figures->isAboveMaxKarat()) {
                    $reason = trim((string) $this->input('override_reason'));

                    if (mb_strlen($reason) < 5) {
                        $validator->errors()->add('override_reason', 'Say why this karat is allowed, so the next person can see.');

                        return;
                    }

                    $this->figures = $this->figures->withOverride($reason, $this->user()->id);
                }

                $context = app(TenantContext::class);

                if ($this->route('type') === DocumentType::Sale->value
                    && $this->figures->weightH > LotAllocator::availableH($context->branch())) {
                    $validator->errors()->add('weight', sprintf(
                        'Not enough gold in stock. %s has %s g and this sale is %s g.',
                        $context->branch()->name,
                        Money::format(LotAllocator::availableH($context->branch())),
                        Money::format($this->figures->weightH),
                    ));
                }
            },
        ];
    }

    /**
     * A sale of the exact purchases the seller chose: [{id, weight}, ...] from this branch's stock. Each lot gives its
     * own karat, so nothing is weighed again. The lots are found through the usual scopes, so only this branch's.
     *
     * @return array{0: GoldFigures, 1: list<array{lotId: int, weightH: int}>}
     */
    public static function calculateLots(User $user, array $lots, string $price): array
    {
        $ids = array_map(fn ($row) => (int) ($row['id'] ?? 0), $lots);

        if ($ids === [] || count($ids) !== count(array_unique($ids))) {
            throw new CalculationError($ids === [] ? 'Choose the purchases to sell, and how much of each.' : 'The same purchase was chosen twice.', 'lots');
        }

        $found = StockLot::query()->with(['goldLine.document', 'document'])->whereIn('id', $ids)->get()->keyBy('id');
        $slices = [];
        $picks = [];

        foreach ($lots as $row) {
            $lot = $found->get((int) ($row['id'] ?? 0));

            if ($lot === null) {
                throw new CalculationError('One of the purchases chosen is not in this branch\'s stock.', 'lots');
            }

            $name = ($lot->goldLine?->document ?? $lot->document)?->number ?? 'purchase '.$lot->id;

            try {
                $weightH = Money::cents((string) ($row['weight'] ?? ''));
            } catch (\InvalidArgumentException) {
                throw new CalculationError("The weight chosen from {$name} must be a number, for example 250.50.", 'lots');
            }

            $remainingH = Money::fromDb($lot->remaining_g);

            if ($weightH <= 0 || $weightH > $remainingH) {
                throw new CalculationError(sprintf(
                    $weightH <= 0 ? 'The weight chosen from %s must be more than zero.' : 'Only %2$s g is left of %1$s.',
                    $name,
                    Money::format($remainingH),
                ), 'lots');
            }

            $slices[] = ['form' => (string) $lot->form, 'karatH' => Money::fromDb($lot->karat), 'weightH' => $weightH];
            $picks[] = ['lotId' => $lot->id, 'weightH' => $weightH];
        }

        return [GoldCalculator::forLots(FormulaSet::currentFor($user->tenant_id), $slices, $price), $picks];
    }

    /**
     * Raw gold gets its karat from the density. Refined gold has its karat typed in. A karat above 24 is refused unless
     * the user is an admin who ticked the override; anyone else is told to ask one.
     */
    public static function calculate(User $user, array $input): GoldFigures
    {
        $formula = FormulaSet::currentFor($user->tenant_id);
        $allow = $user->isTenantAdmin() && filter_var($input['karat_override'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            return ($input['form'] ?? '') === 'refined'
                ? GoldCalculator::refined($formula, (string) ($input['weight'] ?? ''), (string) ($input['karat'] ?? ''), (string) ($input['price'] ?? ''), $allow)
                : GoldCalculator::raw($formula, (string) ($input['weight'] ?? ''), (string) ($input['water_weight'] ?? ''), (string) ($input['price'] ?? ''), $input['big_weight'] ?? null, $allow);
        } catch (CalculationError $error) {
            if (! $error->overridable) {
                throw $error;
            }

            throw new CalculationError(
                $error->getMessage().' '.($user->isTenantAdmin()
                    ? 'If the weighing is right, tick "Allow this karat" and say why.'
                    : 'If the weighing is right, ask an admin to record it.'),
                $error->field,
                true,
            );
        }
    }

    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'The date cannot be in the future.',
            'date.date_format' => 'Enter the date as day, month and year.',
            'date.required' => 'Enter the date.',
            'ledger_id.required' => 'Choose a customer or supplier from your list.',
            'ledger_id.integer' => 'Choose a customer or supplier from your list.',
            'weight.required' => 'Enter the weight.',
            'price.required' => 'Enter the price.',
            'water_weight.required' => 'Enter the water weight.',
            'karat.required' => 'Enter the karat.',
        ];
    }
}
