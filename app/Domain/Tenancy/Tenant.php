<?php

namespace App\Domain\Tenancy;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = ['name', 'plan', 'base_currency', 'status'];

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
