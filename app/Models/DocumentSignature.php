<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A signature drawn on a till's screen for a printable document — see the
 * Flutter app's document_signatures.dart. Fixed once captured; only the
 * void fields ever change.
 */
class DocumentSignature extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'document_type', 'document_id', 'slot',
        'signer_name', 'image_png', 'signed_by_user_id', 'signed_at',
        'voided_at', 'voided_by_user_id',
    ];

    protected $hidden = ['image_png'];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
