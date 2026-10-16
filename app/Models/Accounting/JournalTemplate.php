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
        'created_by_user_id', 'recur_frequency', 'recur_next_date', 'recur_end_date', 'recur_active',
    ];

    protected function casts(): array
    {
        return ['lines' => 'array', 'recur_next_date' => 'date', 'recur_end_date' => 'date', 'recur_active' => 'boolean'];
    }
}
