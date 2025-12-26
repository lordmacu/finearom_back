<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use Illuminate\Support\Facades\Auth;

class ProductObserver
{
    /**
     * Handle the Product "created" event.
     */
    public function created(Product $product): void
    {
        // When a product is created, save the initial price to history
        ProductPriceHistory::create([
            'product_id' => $product->id,
            'price' => $product->price,
            'effective_date' => now(),
            'created_by' => Auth::id(),
        ]);
    }

    /**
     * Handle the Product "updating" event.
     */
    public function updating(Product $product): void
    {
        // Check if the price has changed
        if ($product->isDirty('price')) {
            $originalPrice = $product->getOriginal('price');
            $newPrice = $product->price;

            // Only create a new history record if the price actually changed
            if ($originalPrice != $newPrice) {
                ProductPriceHistory::create([
                    'product_id' => $product->id,
                    'price' => $newPrice,
                    'effective_date' => now(),
                    'created_by' => Auth::id(),
                ]);
            }
        }
    }
}
