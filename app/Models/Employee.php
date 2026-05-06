<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'user_id', 'name', 'job_title', 'department',
        'phone', 'email', 'national_id', 'address',
        'emergency_contact_name', 'emergency_contact_phone',
        'pay_type', 'salary_amount', 'currency_code',
        'hire_date', 'termination_date', 'notes', 'status', 'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'salary_amount'    => 'decimal:4',
            'hire_date'        => 'datetime',
            'termination_date' => 'datetime',
        ];
    }

    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class);
    }
}
