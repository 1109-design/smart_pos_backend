<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Composite-keyed (business_id, document_type) — no surrogate id, same shape as RolePermission. */
class DocumentBrandingSetting extends Model
{
    protected $table = 'document_branding_settings';

    public $incrementing = false;

    protected $primaryKey = null;

    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'business_id',
        'document_type',
        'use_letterhead',
        'use_footer',
        'show_logo',
        'paper_size',
    ];

    protected function casts(): array
    {
        return [
            'use_letterhead' => 'boolean',
            'use_footer' => 'boolean',
            'show_logo' => 'boolean',
        ];
    }
}
