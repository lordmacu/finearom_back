<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'products';

    protected $fillable = [
        'code',
        'product_name',
        'price',
        'client_id',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function discounts()
    {
        return $this->hasMany(ProductDiscount::class)->orderBy('min_quantity', 'asc');
    }

    /**
     * Get all price history records for this product.
     */
    public function priceHistory()
    {
        return $this->hasMany(ProductPriceHistory::class)->orderBy('effective_date', 'desc');
    }

    /**
     * Get the latest price from history.
     */
    public function latestPrice()
    {
        return $this->priceHistory()->latest('effective_date')->first()?->price ?? $this->price;
    }

    /**
     * Get the current price (always uses the price column).
     */
    public function getCurrentPrice()
    {
        return $this->price;
    }
}

