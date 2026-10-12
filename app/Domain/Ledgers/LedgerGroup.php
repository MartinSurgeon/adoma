<?php

namespace App\Domain\Ledgers;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerGroup extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'parent_id', 'key', 'name', 'nature', 'sort_order', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** "Current Assets / Debtors": the group and the one above it, for lists. */
    public function path(): string
    {
        return $this->parent ? $this->parent->name.' / '.$this->name : $this->name;
    }
}
