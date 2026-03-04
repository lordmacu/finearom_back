<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IaForecastBatchRun extends Model
{
    public const MODE_PROCESS_ALL = 'PROCESS_ALL';
    public const MODE_FORCE_RESTART = 'FORCE_RESTART';

    public const STATUS_QUEUED = 'QUEUED';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_COMPLETED_WITH_ERRORS = 'COMPLETED_WITH_ERRORS';
    public const STATUS_FAILED = 'FAILED';

    protected $table = 'ia_forecast_batch_runs';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(IaForecastBatchRunItem::class, 'batch_run_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }
}
