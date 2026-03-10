<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinearomPriceHistory extends Model
{
    protected $table = 'finearom_price_history';

    protected $fillable = [
        'finearom_reference_id',
        'precio_anterior',
        'precio_nuevo',
        'changed_by',
    ];

    protected $casts = [
        'precio_anterior' => 'decimal:2',
        'precio_nuevo'    => 'decimal:2',
    ];

    public function reference(): BelongsTo
    {
        return $this->belongsTo(FinearomReference::class, 'finearom_reference_id');
    }
}
