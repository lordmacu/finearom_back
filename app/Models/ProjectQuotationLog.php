<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectQuotationLog extends Model
{
    protected $table = 'project_quotation_logs';

    protected $fillable = [
        'project_id',
        'version',
        'enviado_a',
        'ejecutivo',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
