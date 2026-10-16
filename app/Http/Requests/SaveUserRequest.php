<?php

namespace App\Http\Requests;

use App\Domain\Tenancy\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Used for both creating and editing a user. */
class SaveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isTenantAdmin() ?? false;
    }

    public function rules(): array
    {
        $actor = $this->user();
        $editing = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($editing),
            ],
            // Null means an ordinary user who works in the branches listed below.
            // Making someone an owner or admin is an owner-only decision.
            'tenant_role' => [
                'nullable',
                $actor->can('assignAdminRoles', \App\Models\User::class)
                    ? Rule::in([Role::Owner->value, Role::Admin->value])
                    : 'prohibited',
            ],
            // Letting an admin add and edit branches: an owner-only decision, like making someone an admin.
            'can_manage_branches' => [
                $actor->can('grantBranchManagement', \App\Models\User::class) ? 'boolean' : 'prohibited',
            ],
            'offices' => ['array'],
            'offices.*.branch_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('branches', 'id')->where('tenant_id', $actor->tenant_id),
            ],
            'offices.*.role' => ['required', Rule::in(array_map(fn (Role $r) => $r->value, Role::branchRoles()))],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // An ordinary user with no branch could sign in and see nothing.
                if ($this->input('tenant_role') === null && count($this->input('offices', [])) === 0) {
                    $validator->errors()->add('offices', 'Choose at least one branch for this person.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'tenant_role.prohibited' => 'Only an owner can make someone an owner or an admin.',
            'can_manage_branches.prohibited' => 'Only an owner can let an admin add and edit branches.',
            'email.unique' => 'Someone already uses this email address.',
            'offices.*.branch_id.exists' => 'That branch does not belong to your business.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // An empty choice from the form means "ordinary user".
        $this->merge(['tenant_role' => $this->input('tenant_role') ?: null]);
    }
}
