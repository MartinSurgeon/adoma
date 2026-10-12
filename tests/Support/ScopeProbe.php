<?php

namespace Tests\Support;

use App\Domain\Tenancy\BelongsToBranch;
use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A stand-in for any real tenant and office scoped model (ledgers, documents, stock).
 * The test creates its table, so the scoping rules are tested before those exist.
 */
class ScopeProbe extends Model
{
    use BelongsToBranch, BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];
}
