<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'client_id',
        'branch_office_id',
        'order_creation_date',
        'required_delivery_date',
        'trm',
        'order_consecutive',
        'observations',
        'delivery_address',
        'status',
        'message_id',
        'message_despacho_id',
        'delivery_city',
        'contact',
        'attachment',
        'phone',
        'invoice_number',
        'dispatch_date',
        'tracking_number',
        'observations_extra',
        'internal_observations',
        'tag_email_despachos',
        'tag_email_pedidos',
        'trm_updated_at',
        'is_new_win',
        'is_muestra',
        'invoice_pdf',
        'subject_client',
        'subject_despacho',
        'proforma_generada',
    ];

    protected $casts = [
        'order_creation_date'      => 'date',
        'required_delivery_date'   => 'date',
        'dispatch_date'            => 'date',
        'trm_updated_at'           => 'datetime',
        'is_new_win'               => 'boolean',
        'is_muestra'               => 'boolean',
        'proforma_generada'        => 'boolean',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function branchOffice()
    {
        return $this->belongsTo(BranchOffice::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'purchase_order_product', 'purchase_order_id', 'product_id')
            ->withPivot('id', 'quantity', 'price', 'branch_office_id', 'new_win', 'muestra', 'delivery_date', 'cierre_cartera', 'parcial');
    }

    public function getBranchOfficeName(Product $product)
    {
        $branchOffice = BranchOffice::find($product->pivot->branch_office_id);
        return $branchOffice ? $branchOffice->name : 'N/A';
    }

    public function partials()
    {
        return $this->hasMany(Partial::class, 'order_id');
    }

    public function comments()
    {
        return $this->hasMany(PurchaseOrderComment::class);
    }
}
