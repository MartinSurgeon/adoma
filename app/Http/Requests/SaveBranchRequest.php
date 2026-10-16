<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for adding a branch and for editing one. The code is chosen once: it is part of every voucher
 * number the branch prints (SAL-KUM-000001), so it never changes after the branch is added.
 */
class SaveBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        return $branch ? $this->user()->can('update', $branch) : $this->user()->can('create', \App\Domain\Tenancy\Branch::class);
    }

    public function rules(): array
    {
        $tenant = $this->user()->tenant_id;
        $branch = $this->route('branch');

        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('branches', 'name')->where('tenant_id', $tenant)->ignore($branch?->id),
            ],
            'code' => $branch ? ['prohibited'] : [
                'required', 'string', 'regex:/^[A-Z0-9]{2,5}$/',
                Rule::unique('branches', 'code')->where('tenant_id', $tenant),
            ],
            'region' => ['nullable', 'string', 'max:60'],
            'is_head_office' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Another branch already has this name.',
            'code.regex' => 'Use 2 to 5 capital letters or numbers, such as TAK.',
            'code.unique' => 'Another branch already uses this code.',
            'code.prohibited' => 'A branch code cannot change: it is part of every voucher number the branch has printed.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'region' => trim((string) $this->input('region')) ?: null,
            'is_head_office' => $this->boolean('is_head_office'),
            ...($this->has('code') ? ['code' => strtoupper(trim((string) $this->input('code')))] : []),
        ]);
    }
}
