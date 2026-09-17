<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Process extends Model
{
    /** Lista única de proyectos: recibe todos los correos del hilo de proyectos. */
    public const PROYECTOS = 'proyectos';

    /**
     * Tipos de proceso válidos (destinatarios de correos automáticos). Deben
     * coincidir con PROCESS_TYPES de GeneralSettings.vue.
     */
    public const TYPES = [
        'orden_de_compra',
        'confirmacion_despacho',
        'pedido',
        self::PROYECTOS,
    ];

    protected $table = 'processes';

    protected $fillable = [
        'name',
        'email',
        'process_type',
    ];
}

