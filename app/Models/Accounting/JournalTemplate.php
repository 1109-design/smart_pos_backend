<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A saved journal to start new manual journals from. */
class JournalTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'name', 'journal_type', 'narration', 'lines',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return ['lines' => 'array'];
    }
}
