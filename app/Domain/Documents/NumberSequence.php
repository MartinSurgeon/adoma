<?php

namespace App\Domain\Documents;

use Illuminate\Database\Eloquent\Model;

/**
 * The counter behind voucher numbers: one row per branch and document type.
 * Nothing else reads or writes it. Use VoucherNumbers::next().
 */
class NumberSequence extends Model
{
    protected $fillable = ['tenant_id', 'branch_id', 'type', 'next_value'];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }
}
