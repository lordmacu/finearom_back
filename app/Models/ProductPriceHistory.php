<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductPriceHistory extends Model
{
    use HasFactory;

    protected $table = 'product_price_history';

    protected $fillable = [
        'product_id',
        'price',
        'effective_date',
        'created_by',
    ];

    protected $casts = [
        'effective_date' => 'datetime',
        'price' => 'decimal:2',
    ];

    /**
     * Get the product that owns this price history record.
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the user who created this price history record.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
