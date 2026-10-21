<?php

namespace App\Domain\Gold;

use App\Domain\Audit\Auditable;
use App\Domain\Tenancy\BelongsToTenant;
use App\Domain\Tenancy\TenancyViolation;
use App\Domain\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The constants behind the gold calculation, as one numbered version.
 * A version is never edited. A change makes a new version, and every gold line
 * remembers the version it was worked out with, so old figures can always be explained.
 */
class FormulaSet extends Model
{
    use Auditable, BelongsToTenant;

    /** The constants from the client's Excel sheet. */
    public const DEFAULTS = [
        'grams_per_pound' => '7.75',
        'density_offset' => '10.51',
        'karat_factor' => '52.838',
        'karat_base' => 23,
        // Taken off the karat before a purchase is priced: the sheet's "Karat (-0.7%)" column.
        'karat_deduction' => '0.16',
    ];

    protected $fillable = [
        'tenant_id', 'version', 'grams_per_pound', 'density_offset', 'karat_factor', 'karat_base', 'karat_deduction', 'effective_from', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'karat_base' => 'integer',
            'grams_per_pound' => 'decimal:2', 'density_offset' => 'decimal:2', 'karat_factor' => 'decimal:3',
            'karat_deduction' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new TenancyViolation('A formula version cannot be changed. Make a new version.'));
        static::deleting(fn () => throw new TenancyViolation('A formula version cannot be deleted.'));
    }

    public function auditLabel(): string
    {
        return 'Gold formulas version '.$this->version;
    }

    /** The version in force for a business. Builds version 1 from the client's sheet if there is none yet. */
    public static function currentFor(int $tenantId): self
    {
        $current = static::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderByDesc('version')->first();

        return $current ?? static::createDefaultFor(Tenant::findOrFail($tenantId));
    }

    public static function createDefaultFor(Tenant $tenant): self
    {
        return static::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'version' => 1],
            [...self::DEFAULTS, 'effective_from' => now()->toDateString()],
        );
    }
}
