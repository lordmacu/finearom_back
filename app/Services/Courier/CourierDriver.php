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
}
