<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\Role;
use App\Domain\Tenancy\Tenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * tenant_id, tenant_role and is_active are deliberately not listed.
     * They control access, so only admin code may set them, one attribute at a time.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * In-memory defaults that match the database defaults, so a user that was
     * just created behaves the same as one loaded from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /** @var array<int, Role>|null Filled by roleMap(). */
    private ?array $roleMap = null;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withPivot('role')->withTimestamps();
    }

    /** Owners and admins work across every branch of their business. */
    public function isTenantAdmin(): bool
    {
        return in_array($this->tenant_role, [Role::Owner->value, Role::Admin->value], true);
    }

    /**
     * Offices this user may work in, head office first.
     * Queries without scopes on purpose: this runs before the context exists.
     *
     * @return Collection<int, Branch>
     */
    public function accessibleBranches(): Collection
    {
        if ($this->tenant_id === null || ! $this->is_active) {
            return new Collection();
        }

        $query = Branch::withoutGlobalScopes()->where('tenant_id', $this->tenant_id);

        if (! $this->isTenantAdmin()) {
            $query->whereIn('id', $this->branches()->withoutGlobalScopes()->pluck('branches.id'));
        }

        return $query->orderByDesc('is_head_office')->orderBy('name')->get();
    }

    /** The user's role in one branch, or null when they have no access to it. */
    public function roleIn(Branch|int|null $branch): ?Role
    {
        if ($branch === null) {
            return null;
        }

        return $this->roleMap()[$branch instanceof Branch ? $branch->id : $branch] ?? null;
    }

    /**
     * The user's role in every branch they can use, as [branch id => Role].
     * Built once per user object, so listing 25 ledgers does not run 25 queries.
     *
     * @return array<int, Role>
     */
    public function roleMap(): array
    {
        return $this->roleMap ??= $this->buildRoleMap();
    }

    /** A refreshed user must not keep roles read before the refresh. */
    public function refresh(): static
    {
        $this->roleMap = null;

        return parent::refresh();
    }

    /** @return array<int, Role> */
    private function buildRoleMap(): array
    {
        if ($this->tenant_id === null || ! $this->is_active) {
            return [];
        }

        if ($this->isTenantAdmin()) {
            $role = Role::from($this->tenant_role);

            return Branch::withoutGlobalScopes()->where('tenant_id', $this->tenant_id)->pluck('id')
                ->mapWithKeys(fn ($id) => [(int) $id => $role])->all();
        }

        // Only offices of the user's own business count, whatever else is in the table.
        return $this->branches()->withoutGlobalScopes()
            ->where('branches.tenant_id', $this->tenant_id)->get()
            ->mapWithKeys(fn (Branch $b) => [$b->id => Role::from($b->pivot->role)])->all();
    }
}
