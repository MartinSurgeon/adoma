<?php

namespace App\Http\Requests;

use App\Domain\Documents\Document;
use App\Domain\Support\Money;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/** Sending gold to another office: a date, the office, and how much. */
class SaveTransferRequest extends FormRequest
{
    /** The receiving office, found only among this business's offices. */
    public ?Branch $toBranch = null;

    /** The weight in hundredths of a gram. */
    public int $weightH = 0;

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
                    $validator->errors()->add('to_branch_id', 'Choose the office the gold is going to.');
                }

                try {
                    $this->weightH = Money::cents($this->input('weight'));

                    if ($this->weightH <= 0) {
                        $validator->errors()->add('weight', 'The weight must be more than zero.');
                    }
                } catch (InvalidArgumentException) {
                    $validator->errors()->add('weight', 'Enter the weight in grams as a number, for example 250 or 250.50.');
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
            'to_branch_id.required' => 'Choose the office the gold is going to.',
            'to_branch_id.integer' => 'Choose the office the gold is going to.',
            'weight.required' => 'Enter the weight.',
        ];
    }
}
