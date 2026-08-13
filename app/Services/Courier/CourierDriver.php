<?php

namespace App\Services\Courier;

interface CourierDriver
{
    /** Clave normalizada de la transportadora, como aparece en partials.transporter. */
    public function key(): string;

    /** ¿Este número de guía tiene el formato de esta transportadora? */
    public function matches(string $trackingNumber): bool;

    /** Consulta la transportadora y devuelve el estado normalizado. */
    public function track(string $trackingNumber): CourierResult;

    /**
     * ¿Esta transportadora empuja sus notificaciones en vez de responder a
     * consultas? Si es true, nunca debe entrar a la cola de sincronización
     * (ver CourierRegistry::pullKeys()): no hay a quién preguntarle.
     */
    public function isPushOnly(): bool;
}
