<?php

namespace App\Domain\Audit;

use App\Domain\Tenancy\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit log. Called by the Auditable trait for every create, change and delete,
 * and directly for events that are not a model change (signing in, a change of access).
 *
 * It writes inside whatever transaction is open. If the change is rolled back, so is its
 * audit line, so the log never describes something that did not happen.
 */
final class Audit
{
    /** Never stored at all. */
    private const SKIP = ['created_at', 'updated_at', 'remember_token', 'email_verified_at'];

    /** Stored as a mask: the log shows that it changed, never what it changed to. */
    private const MASKED = ['password'];

    public static function record(
        string $event,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?Authenticatable $actor = null,
        ?string $actorName = null,
    ): void {
        $context = app(TenantContext::class);
        $actor ??= Auth::user();

        AuditLog::create([
            'tenant_id' => self::tenantId($subject, $actor, $context),
            'branch_id' => $context->branchId(),
            'user_id' => $actor?->getAuthIdentifier(),
            'actor_name' => $actor?->name ?? $actorName,
            'event' => $event,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'label' => $subject ? self::label($subject) : null,
            'old_values' => $old === [] ? null : self::clean($old),
            'new_values' => $new === [] ? null : self::clean($new),
            'ip_address' => app()->bound('request') ? request()->ip() : null,
        ]);
    }

    /** Drop what is never stored and mask what must stay secret. */
    public static function clean(array $values): array
    {
        $values = array_diff_key($values, array_flip(self::SKIP));

        foreach (self::MASKED as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = '***';
            }
        }

        return $values;
    }

    private static function label(Model $subject): string
    {
        $label = method_exists($subject, 'auditLabel')
            ? $subject->auditLabel()
            : ($subject->getAttribute('name') ?? class_basename($subject).' #'.$subject->getKey());

        return mb_substr((string) $label, 0, 160);
    }

    private static function tenantId(?Model $subject, ?Authenticatable $actor, TenantContext $context): ?int
    {
        return match (true) {
            $subject instanceof Tenant => $subject->getKey(),
            $subject?->getAttribute('tenant_id') !== null => (int) $subject->getAttribute('tenant_id'),
            $context->tenantId() !== null => $context->tenantId(),
            default => $actor?->tenant_id,
        };
    }
}
