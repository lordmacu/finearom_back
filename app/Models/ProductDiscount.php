<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductDiscount extends Model
{
    protected $table = 'product_discounts';

    protected $fillable = [
        'product_id',
        'min_quantity',
        'discount_percentage',
    ];

    protected $casts = [
        'min_quantity' => 'float',
        'discount_percentage' => 'float',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
