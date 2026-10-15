<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

/** One nightly integrity check of a business's books (Phase 12.1). */
class IntegrityCheckRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['business_id', 'ok', 'findings', 'ran_at'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'findings' => 'array', 'ran_at' => 'datetime'];
    }
}
