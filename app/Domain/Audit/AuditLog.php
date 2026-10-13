<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One line in the record of who did what. Written once, never changed or removed.
 *
 * Reading is limited to the business in context, and fails closed with none.
 * Writing is not limited, because some events (a failed sign-in) have no business yet.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'branch_id', 'user_id', 'actor_name', 'event',
        'auditable_type', 'auditable_id', 'label', 'old_values', 'new_values', 'ip_address',
    ];

    /** Friendly names for the kinds of record, used in sentences. */
    private const SUBJECTS = ['Ledger' => 'ledger', 'User' => 'user', 'Branch' => 'office', 'Tenant' => 'business', 'Document' => 'document'];

    private const FIELDS = [
        'name' => 'name', 'phone' => 'phone', 'email' => 'email',
        'tenant_role' => 'access level', 'code' => 'code', 'region' => 'region',
        'is_head_office' => 'head office setting', 'status' => 'status', 'plan' => 'plan',
    ];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            $tenantId = app(TenantContext::class)->tenantId();
            $column = $query->getModel()->getTable().'.tenant_id';

            $tenantId === null ? $query->whereRaw('1 = 0') : $query->where($column, $tenantId);
        });

        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }

    /** A plain sentence about what happened, without the name of who did it. */
    public function describe(): string
    {
        $subject = self::SUBJECTS[class_basename((string) $this->auditable_type)] ?? 'record';
        $label = $this->label !== null ? "“{$this->label}”" : '';
        $thing = trim("$subject $label");

        return match ($this->event) {
            'created' => "added $thing",
            'deleted' => "removed $thing",
            'updated' => $this->describeChange($thing),
            'access_changed' => "changed which offices and roles $label has",
            'login' => 'signed in',
            'logout' => 'signed out',
            'login_failed' => 'failed to sign in',
            default => str_replace('_', ' ', $this->event)." $thing",
        };
    }

    public static function fieldLabel(string $field): string
    {
        return self::FIELDS[$field] ?? str_replace('_', ' ', $field);
    }

    /** The changed fields as [field => [old, new]], without anything sensitive. */
    public function changes(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        return collect($new)->except(['must_change_password'])
            ->map(fn ($value, $field) => [$old[$field] ?? null, $value])
            ->all();
    }

    private function describeChange(string $thing): string
    {
        $new = $this->new_values ?? [];

        if (array_key_exists('is_active', $new)) {
            return ($new['is_active'] ? 'switched on ' : 'switched off ').$thing;
        }

        if (array_key_exists('password', $new)) {
            return "changed the password of $thing";
        }

        if (($new['status'] ?? null) === 'reversed') {
            return "marked $thing as reversed";
        }

        $fields = collect(array_keys($new))
            ->reject(fn ($f) => $f === 'must_change_password')
            ->map(fn ($f) => self::fieldLabel($f))
            ->unique()
            ->values();

        return $fields->isEmpty()
            ? "changed $thing"
            : 'changed the '.$fields->join(', ', ' and ')." of $thing";
    }
}
