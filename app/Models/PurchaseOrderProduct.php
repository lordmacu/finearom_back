<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderProduct extends Model
{
    protected $table = 'purchase_order_product';

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'product_name',
        'price',
        'branch_office_id',
        'muestra',
        'parcial',
        'new_win',
        'quantity',
        'delivery_date',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'quantity'      => 'integer',
        'price'         => 'decimal:2',
        'muestra'       => 'boolean',
        'parcial'       => 'boolean',
        'new_win'       => 'boolean',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function branchOffice()
    {
        return $this->belongsTo(BranchOffice::class, 'branch_office_id');
    }

    public function partials()
    {
        return $this->hasMany(Partial::class, 'order_id', 'purchase_order_id')
            ->whereColumn('partials.product_id', 'purchase_order_product.product_id');
    }
}
