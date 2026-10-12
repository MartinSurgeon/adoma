<?php

namespace App\Http\Requests;

use App\Domain\Ledgers\Ledger;
use App\Domain\Ledgers\LedgerKind;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Used for both adding and editing a ledger. */
class SaveLedgerRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $ledger = $this->route('ledger');

        // Editing: only name and phone change, and the policy decides who may.
        if ($ledger instanceof Ledger) {
            return $user->can('update', $ledger);
        }

        return $user->can('create', [Ledger::class, (string) $this->input('kind'), $this->boolean('is_shared')]);
    }

    public function rules(): array
    {
        $editing = $this->route('ledger');
        $context = app(TenantContext::class);
        $branchId = $this->resolvedBranchId($editing);

        $name = Rule::unique('ledgers', 'name')
            ->where('tenant_id', $this->user()->tenant_id)
            ->where(fn ($q) => $branchId === null ? $q->whereNull('branch_id') : $q->where('branch_id', $branchId))
            ->ignore($editing?->id);

        $rules = [
            'name' => ['required', 'string', 'max:120', $name],
            'phone' => ['nullable', 'string', 'max:30'],
        ];

        if ($editing === null) {
            $rules += [
                'kind' => ['required', Rule::in(array_map(fn (LedgerKind $k) => $k->value, LedgerKind::creatable()))],
                'is_shared' => ['sometimes', 'boolean'],
                // Owners and admins viewing all offices must say which office the ledger is for.
                'branch_id' => [
                    Rule::requiredIf($context->viewingAllBranches() && ! $this->boolean('is_shared')),
                    'nullable', 'integer',
                    Rule::exists('branches', 'id')->where('tenant_id', $this->user()->tenant_id),
                ],
            ];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'This office already has a ledger with that name.',
            'branch_id.required' => 'Choose which office this ledger is for.',
            'branch_id.exists' => 'That office does not belong to your business.',
        ];
    }

    /** The office the ledger will belong to, or null for a shared ledger. */
    public function resolvedBranchId(?Ledger $editing = null): ?int
    {
        if ($editing !== null) {
            return $editing->branch_id;
        }

        if ($this->boolean('is_shared')) {
            return null;
        }

        $context = app(TenantContext::class);

        return $context->viewingAllBranches()
            ? ($this->input('branch_id') !== null ? (int) $this->input('branch_id') : null)
            : $context->branchId();
    }
}
