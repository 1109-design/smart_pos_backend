<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSellableLocation extends Model
{

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['product_id', 'location_id'];
}
