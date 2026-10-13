<?php

namespace App\Domain\Documents;

use App\Domain\Ledgers\Ledger;
use App\Domain\Tenancy\BelongsToBranch;
use App\Domain\Tenancy\BelongsToTenant;
use App\Domain\Tenancy\TenancyViolation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a double entry: an amount debited or credited to a ledger, in one office's books.
 * Written once with its document. Never changed, never deleted.
 */
class JournalLine extends Model
{
    use BelongsToBranch, BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'branch_id', 'document_id', 'ledger_id', 'date', 'debit', 'credit'];

    protected function casts(): array
    {
        return ['date' => 'date', 'debit' => 'decimal:2', 'credit' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new TenancyViolation('Journal lines cannot be changed. Post a reversal.'));
        static::deleting(fn () => throw new TenancyViolation('Journal lines cannot be deleted. Post a reversal.'));
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }
}
