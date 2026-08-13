<?php

namespace App\Models;

use App\Services\Courier\CourierRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentTracking extends Model
{
    protected $fillable = [
        'purchase_order_id', 'tracking_number', 'carrier', 'status',
        'last_event_at', 'last_event_code', 'last_event_description', 'last_event_location',
        'dispatch_date', 'total_kg', 'partials_count',
        'checked_at', 'check_attempts', 'is_final', 'error_message',
    ];

    protected $casts = [
        'last_event_at'  => 'datetime',
        'checked_at'     => 'datetime',
        'dispatch_date'  => 'date',
        'total_kg'       => 'decimal:2',
        'partials_count' => 'integer',
        'check_attempts' => 'integer',
        'is_final'       => 'boolean',
    ];

    /**
     * `is_push_only` viaja siempre en el JSON: el frontend (ShipmentTrackingList)
     * lo usa para no ofrecer el botón "Consultar" en filas de transportadoras
     * que, como Coordinadora, nunca se consultan activamente.
     */
    protected $appends = ['is_push_only'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class)->orderBy('occurred_at');
    }

    /**
     * Fuente única de verdad: CourierRegistry, la misma que usa el backend
     * para excluir a Coordinadora de la cola de consulta activa
     * (ShipmentTrackingSyncService::pending() vía pullKeys()). Así el
     * frontend nunca duplica la lista de transportadoras push-only.
     */
    public function getIsPushOnlyAttribute(): bool
    {
        return app(CourierRegistry::class)->driverFor($this->carrier)?->isPushOnly() ?? false;
    }
}
