<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IaForecastClientRunItem extends Model
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_ERROR = 'ERROR';

    protected $table = 'ia_forecast_client_run_items';

    protected $guarded = [];

    protected $casts = [
        'kg_total' => 'float',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'analizado_en' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(IaForecastClientRun::class, 'run_id');
    }
}
