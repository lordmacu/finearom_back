<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawMaterialStockMovement extends Model
{
    use HasFactory;

    protected $table = 'raw_material_stock_movements';

    protected $fillable = [
        'raw_material_id',
        'tipo',
        'cantidad',
        'notas',
        'user_id',
        'fecha',
    ];

    protected $casts = [
        'cantidad' => 'decimal:4',
        'fecha'    => 'date',
    ];

    public function rawMaterial(): BelongsTo
    {
        return $this->belongsTo(RawMaterial::class, 'raw_material_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
