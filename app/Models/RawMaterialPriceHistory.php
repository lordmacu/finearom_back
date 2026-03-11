<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialPriceHistory extends Model
{
    use HasFactory;

    protected $table = 'raw_material_price_history';

    protected $fillable = [
        'raw_material_id',
        'costo_anterior',
        'costo_nuevo',
        'changed_by',
    ];

    protected $casts = [
        'costo_anterior' => 'decimal:4',
        'costo_nuevo'    => 'decimal:4',
    ];

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'raw_material_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
