<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolePermission extends Model
{

    protected $table = 'role_permissions';

    public $incrementing = false;

    protected $primaryKey = null;

    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = ['business_id', 'role', 'permissions_json'];

    protected function casts(): array
    {
        return [
            'permissions_json' => 'array',
        ];
    }
}
