<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderComment extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'user_id',
        'text',
        'type',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
