<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeApplication extends Model
{
    use HasFactory;

    protected $table = 'time_applications';

    protected $fillable = [
        'rango_min',
        'rango_max',
        'tipo_cliente',
        'product_id',
        'valor',
    ];

    protected $casts = [
        'rango_min' => 'decimal:2',
        'rango_max' => 'decimal:2',
        'valor' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
