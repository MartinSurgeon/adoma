<?php

namespace App\Domain\Documents;

use App\Domain\Tenancy\Branch;
use App\Domain\Tenancy\TenancyViolation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Voucher numbers such as PCH-KUM-000042: letters for the document type, the office
 * code, then a running number for that office and type.
 *
 * Numbers never repeat, and they never skip. next() takes the office's counter row
 * with a database lock and adds one. It runs inside a transaction, so when it is called
 * from a posting that later fails, the whole posting rolls back and the number goes back
 * with it. Two clerks saving at the same moment queue up on the lock, one after the other.
 */
final class VoucherNumbers
{
    public static function next(Branch $branch, DocumentType $type): string
    {
        self::assertSameBusiness($branch);

        // Three attempts: if the database picks this request to give up in a lock clash, it runs again.
        return DB::transaction(function () use ($branch, $type) {
            $sequence = self::lockedSequence($branch, $type);
            $number = $sequence->next_value;

            $sequence->forceFill(['next_value' => $number + 1])->save();

            return self::format($branch, $type, $number);
        }, 3);
    }

    /** Make sure an office has a counter for every document type. Safe to run again. */
    public static function ensureFor(Branch $branch): void
    {
        foreach (DocumentType::cases() as $type) {
            NumberSequence::firstOrCreate(
                ['branch_id' => $branch->id, 'type' => $type->value],
                ['tenant_id' => $branch->tenant_id, 'next_value' => 1],
            );
        }
    }

    public static function format(Branch $branch, DocumentType $type, int $number): string
    {
        return sprintf('%s-%s-%06d', $type->prefix(), $branch->code, $number);
    }

    private static function lockedSequence(Branch $branch, DocumentType $type): NumberSequence
    {
        $counter = fn () => NumberSequence::where('branch_id', $branch->id)->where('type', $type->value);

        // First use only. Look without locking, then insert-or-ignore: if another request makes the
        // counter at the same moment, the loser's insert is quietly skipped. Locking first would let
        // two requests each hold a lock the other's insert needs, and the database would cancel one.
        if (! $counter()->exists()) {
            NumberSequence::insertOrIgnore([[
                'tenant_id' => $branch->tenant_id,
                'branch_id' => $branch->id,
                'type' => $type->value,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
        }

        // The counter exists now. Take its row lock and keep it until the transaction ends.
        return $counter()->lockForUpdate()->firstOrFail();
    }

    /**
     * A document may be numbered for any office of the business (a transfer is numbered in
     * both offices), but never for another business.
     */
    private static function assertSameBusiness(Branch $branch): void
    {
        $tenantId = app(TenantContext::class)->tenantId();

        if ($tenantId !== null && $branch->tenant_id !== $tenantId) {
            throw new TenancyViolation('Cannot number a document for an office of another business.');
        }
    }
}
