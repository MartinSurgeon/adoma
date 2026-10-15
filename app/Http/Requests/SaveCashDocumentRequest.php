<?php

namespace App\Http\Requests;

use App\Domain\Documents\Document;
use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/** A receipt or a payment: a date, an amount, who it is with, and which cash or bank account. */
class SaveCashDocumentRequest extends FormRequest
{
    /** The ledgers behind the two ids, found only if this branch may use them. */
    public ?Ledger $party = null;

    public ?Ledger $cash = null;

    public int $cents = 0;

    public function authorize(): bool
    {
        return $this->user()->can('create', [Document::class, (string) $this->route('type')]);
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:today'],
            'ledger_id' => ['required', 'integer'],
            'cash_ledger_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // The ledgers are looked up through the normal scopes, so a branch can only
                // find its own ledgers and the shared ones, never another branch's.
                $this->party = Ledger::query()->whereKey($this->integer('ledger_id'))->where('is_active', true)
                    ->whereIn('kind', array_map(fn (LedgerKind $k) => $k->value, [
                        LedgerKind::Customer, LedgerKind::Supplier, LedgerKind::Bank,
                        LedgerKind::Cash, LedgerKind::Expense, LedgerKind::Branch,
                    ]))->first();

                $this->cash = Ledger::query()->whereKey($this->integer('cash_ledger_id'))->where('is_active', true)
                    ->whereIn('kind', [LedgerKind::Cash->value, LedgerKind::Bank->value])->first();

                if ($this->party === null) {
                    $validator->errors()->add('ledger_id', 'Choose someone from your list of ledgers.');
                }

                if ($this->cash === null) {
                    $validator->errors()->add('cash_ledger_id', 'Choose a cash or bank account.');
                }

                if ($this->party && $this->cash && $this->party->is($this->cash)) {
                    $validator->errors()->add('cash_ledger_id', 'Choose a different account from the other party.');
                }

                try {
                    $this->cents = Money::cents($this->input('amount'));

                    if ($this->cents <= 0) {
                        $validator->errors()->add('amount', 'The amount must be more than zero.');
                    }
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('amount', 'Enter the amount as a number, for example 1500 or 1500.50.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'The date cannot be in the future.',
            'date.date_format' => 'Enter the date as day, month and year.',
            'date.required' => 'Enter the date.',
            'ledger_id.required' => 'Choose someone from your list of ledgers.',
            'ledger_id.integer' => 'Choose someone from your list of ledgers.',
            'cash_ledger_id.required' => 'Choose a cash or bank account.',
            'cash_ledger_id.integer' => 'Choose a cash or bank account.',
            'amount.required' => 'Enter the amount.',
        ];
    }
}
