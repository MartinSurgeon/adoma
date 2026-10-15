<?php

namespace App\Http\Requests;

use App\Domain\Documents\Document;
use App\Domain\Gold\GoldCalculator;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/** Sending gold to another branch: a date, the branch, and how much. */
class SaveTransferRequest extends FormRequest
{
    /** The receiving branch, found only among this business's offices. */
    public ?Branch $toBranch = null;

    /** The weight in hundredths of a gram. */
    public int $weightH = 0;

    /** The price per pound, in hundredths of a cedi. */
    public int $priceH = 0;

    public function authorize(): bool
    {
        return $this->user()->can('create', [Document::class, 'transfer']);
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before_or_equal:today'],
            'to_branch_id' => ['required', 'integer'],
            'weight' => ['required', 'string', 'max:20'],
            'price' => ['required', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Offices are read through the business scope, so only this business's offices can be found.
                $this->toBranch = Branch::query()
                    ->whereKey($this->integer('to_branch_id'))
                    ->where('id', '!=', app(TenantContext::class)->branchId())
                    ->first();

                if ($this->toBranch === null && ! $validator->errors()->has('to_branch_id')) {
                    $validator->errors()->add('to_branch_id', 'Choose the branch the gold is going to.');
                }

                try {
                    $this->weightH = Money::cents($this->input('weight'));

                    if ($this->weightH <= 0) {
                        $validator->errors()->add('weight', 'The weight must be more than zero.');
                    }
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('weight', 'Enter the weight in grams as a number, for example 250 or 250.50.');
                }

                try {
                    $this->priceH = Money::cents($this->input('price'));

                    if ($this->priceH <= 0) {
                        $validator->errors()->add('price', 'The price must be more than zero.');
                    } elseif ($this->priceH > GoldCalculator::MAX_PRICE * 100) {
                        $validator->errors()->add('price', 'The price is more than '.number_format(GoldCalculator::MAX_PRICE).', which looks like a typing mistake.');
                    }
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('price', 'Enter the price as a number, for example 10600.');
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
            'to_branch_id.required' => 'Choose the branch the gold is going to.',
            'to_branch_id.integer' => 'Choose the branch the gold is going to.',
            'weight.required' => 'Enter the weight.',
            'price.required' => 'Enter the price per pound the gold is being sold to the other branch at.',
        ];
    }
}
