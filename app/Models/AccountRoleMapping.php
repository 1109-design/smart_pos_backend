<?php

namespace App\Models;

use App\Models\Accounting\GlAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountRoleMapping extends Model
{
    use HasUuids;

    protected $fillable = ['id', 'business_id', 'role', 'gl_account_id'];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class);
    }
}
