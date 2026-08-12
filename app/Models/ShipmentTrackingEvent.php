<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentTrackingEvent extends Model
{
    protected $fillable = [
        'shipment_tracking_id', 'occurred_at', 'code', 'description', 'location', 'raw',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'raw'         => 'array',
    ];

    public function tracking(): BelongsTo
    {
        return $this->belongsTo(ShipmentTracking::class, 'shipment_tracking_id');
    }
}
