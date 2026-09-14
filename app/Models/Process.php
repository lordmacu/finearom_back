<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Process extends Model
{
    /**
     * Tipos de proceso válidos (destinatarios de correos automáticos).
     * Cada acción de proyecto que envía correo agrega aquí su `project_{accion}`
     * y la misma entrada en PROCESS_TYPES de GeneralSettings.vue.
     */
    public const TYPES = [
        'orden_de_compra',
        'confirmacion_despacho',
        'pedido',
        'project_created',
        'project_updated',
        'project_development_delivered',
        'project_applications_ready',
        'project_evaluation_delivered',
        'project_marketing_delivered',
        'project_regulatoria_delivered',
        'project_especiales_delivered',
    ];

    protected $table = 'processes';

    protected $fillable = [
        'name',
        'email',
        'process_type',
    ];
}

