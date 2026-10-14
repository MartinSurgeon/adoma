<?php

namespace App\Domain\Documents;

use App\Domain\Audit\Auditable;
use App\Domain\Gold\GoldLine;
use App\Domain\Ledgers\Ledger;
use App\Domain\Tenancy\BelongsToBranch;
use App\Domain\Tenancy\BelongsToTenant;
use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenancyViolation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A posted transaction: a receipt, a payment, and later purchases, sales and transfers.
 *
 * A posted document is never edited and never deleted. The one change allowed is its
 * status moving from posted to reversed, when a reversing document is posted against it.
 * Seen only by its own office, or by admins viewing all offices of their business.
 */
class Document extends Model
{
    use Auditable, BelongsToBranch, BelongsToTenant;

    public const POSTED = 'posted';

    public const REVERSED = 'reversed';

    protected $fillable = [
        'tenant_id', 'branch_id', 'type', 'number', 'date', 'ledger_id', 'cash_ledger_id',
        'total', 'note', 'status', 'reverses_id', 'idempotency_key', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'date' => 'date',
            'total' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Document $document) {
            $changed = array_keys($document->getDirty());

            // Only the status may move, and only from posted to reversed.
            $onlyStatus = array_diff($changed, ['status', 'updated_at']) === [];
            $validMove = $document->getOriginal('status') === self::POSTED && $document->status === self::REVERSED;

            if (! $onlyStatus || ($document->isDirty('status') && ! $validMove)) {
                throw new TenancyViolation('A posted document cannot be changed. Reverse it and post a new one.');
            }
        });

        static::deleting(fn () => throw new TenancyViolation('A posted document cannot be deleted. Reverse it instead.'));
    }

    public function auditLabel(): string
    {
        return $this->number;
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function cashLedger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'cash_ledger_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }

    /** The weighing and the figures, for a purchase or a sale. */
    public function goldLine(): HasOne
    {
        return $this->hasOne(GoldLine::class);
    }

    /** The document this one undoes, if it is a reversal. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** The document that undid this one, if it has been reversed. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }
}
