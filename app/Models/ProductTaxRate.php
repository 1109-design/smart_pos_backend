<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductTaxRate extends Model
{

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['product_id', 'tax_rate_id'];
}
