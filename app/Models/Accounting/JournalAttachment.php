<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A file attached to a manual journal; the file itself is stored privately. */
class JournalAttachment extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'manual_journal_id', 'file_name', 'file_path',
        'size_bytes', 'created_by_user_id',
    ];
}
