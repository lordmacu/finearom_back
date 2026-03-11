<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientVisitCommitment extends Model
{
    use HasFactory;

    protected $table = 'client_visit_commitments';

    protected $fillable = [
        'visit_id',
        'descripcion',
        'responsable',
        'fecha_estimada',
        'completado',
    ];

    protected $casts = [
        'fecha_estimada' => 'date',
        'completado'     => 'boolean',
    ];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(ClientVisit::class, 'visit_id');
    }
}
