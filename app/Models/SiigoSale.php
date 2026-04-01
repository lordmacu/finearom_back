<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiigoSale extends Model
{
    protected $table = 'siigo_sales';

    protected $fillable = [
        'nit',
        'cuenta',
        'orden_compra',
        'lote',
        'product_code',
        'precio_unitario',
        'mes',
        'valor',
        'cantidad',
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'valor' => 'decimal:2',
        'cantidad' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'nit', 'nit');
    }

    public function siigoProduct(): BelongsTo
    {
        return $this->belongsTo(SiigoProduct::class, 'producto', 'codigo');
    }
}
