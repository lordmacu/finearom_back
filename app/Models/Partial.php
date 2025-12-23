<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Partial extends Model
{
    use SoftDeletes;

    protected $table = 'partials';

    protected $fillable = [
        'product_id',
        'order_id',
        'quantity',
        'type',
        'dispatch_date',
        'invoice_number',
        'tracking_number',
        'transporter',
        'trm',
        'product_order_id',
    ];

    protected $casts = [
        'dispatch_date' => 'date',
        'quantity' => 'integer',
    ];

    /**
     * Product related to this partial.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * Purchase order related to this partial.
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'order_id');
    }
}
