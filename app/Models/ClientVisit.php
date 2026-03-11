<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientVisit extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'client_visits';

    protected $fillable = [
        'client_id',
        'prospect_id',
        'nombre_cliente',
        'user_id',
        'titulo',
        'fecha_inicio',
        'fecha_fin',
        'lugar',
        'tipo',
        'notas',
        'google_event_id',
        'google_event_link',
        'estado',
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_fin'    => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(ClientVisitCommitment::class, 'visit_id');
    }
}
