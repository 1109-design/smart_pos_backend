<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TillLocationAudit extends Model
{
    protected $table = 'till_location_audits';

    const UPDATED_AT = null;

    protected $fillable = [
        'business_id', 'till_id', 'from_location_id', 'to_location_id',
        'changed_by_user_id', 'changed_by_user_name',
    ];
}
