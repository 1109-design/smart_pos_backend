<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackOfficeRolePermission extends Model
{
    protected $table = 'backoffice_role_permissions';

    protected $fillable = ['business_id', 'role', 'permissions_json'];

    protected function casts(): array
    {
        return [
            'permissions_json' => 'array',
        ];
    }
}
