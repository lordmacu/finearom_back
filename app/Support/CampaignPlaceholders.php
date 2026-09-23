<?php

namespace App\Support;

use App\Models\Client;

/**
 * Placeholders de las campañas de correo.
 *
 * Misma sintaxis que las plantillas del sistema: |variable|.
 *
 * IMPORTANTE: el reemplazo se hace UNA VEZ POR DESTINATARIO, dentro del job
 * SendCampaignEmail, que corre con el cliente de ese envío. Nunca se debe
 * reemplazar sobre la campaña guardada ni reutilizar el resultado entre
 * destinatarios: eso le mandaría a un cliente el nombre y el NIT de otro.
 */
final class CampaignPlaceholders
{
    /**
     * Placeholder => [columna del cliente, etiqueta para la interfaz].
     *
     * Para agregar uno nuevo basta con sumarlo aquí: el backend lo reemplaza y
     * el formulario lo ofrece como botón, sin tocar nada más.
     */
    public const MAPA = [
        'company'   => ['client_name',             'Nombre del cliente'],
        'nit'       => ['nit',                     'NIT'],
        'ciudad'    => ['city',                    'Ciudad'],
        'direccion' => ['address',                 'Dirección'],
        'ejecutiva' => ['executive',               'Ejecutiva asignada'],
        'contacto'  => ['purchasing_contact_name', 'Contacto de compras'],
    ];

    /** Qué se pone cuando el envío no tiene cliente (correos escritos a mano). */
    public const SIN_CLIENTE = ['company' => 'cliente'];

    /**
     * Reemplaza los placeholders del texto con los datos de ESTE cliente.
     *
     * @param  Client|null  $client  el cliente del envío en curso; null en los
     *                               correos manuales
     */
    public static function replace(?string $texto, ?Client $client): string
    {
        if ($texto === null || $texto === '') {
            return (string) $texto;
        }

        foreach (self::valuesFor($client) as $clave => $valor) {
            $texto = str_replace("|{$clave}|", $valor, $texto);
        }

        return $texto;
    }

    /**
     * Valores de cada placeholder para un cliente.
     *
     * @return array<string, string>
     */
    public static function valuesFor(?Client $client): array
    {
        $valores = [];

        foreach (self::MAPA as $clave => [$columna, $_etiqueta]) {
            $valor = $client?->{$columna};
            $valores[$clave] = is_scalar($valor) ? trim((string) $valor) : '';

            if ($valores[$clave] === '') {
                $valores[$clave] = self::SIN_CLIENTE[$clave] ?? '';
            }
        }

        return $valores;
    }

    /**
     * Valores de muestra para el correo de prueba, para que se vea cómo queda
     * el reemplazo antes de enviarle a nadie.
     *
     * @return array<string, string>
     */
    public static function sampleValues(): array
    {
        return [
            'company'   => 'CLIENTE DE PRUEBA S.A.S.',
            'nit'       => '900.123.456-7',
            'ciudad'    => 'Bogotá',
            'direccion' => 'Calle 100 # 10-20',
            'ejecutiva' => 'Ejecutiva de prueba',
            'contacto'  => 'Contacto de prueba',
        ];
    }

    /** Reemplazo con los valores de muestra (solo para el envío de prueba). */
    public static function replaceWithSample(?string $texto): string
    {
        if ($texto === null || $texto === '') {
            return (string) $texto;
        }

        foreach (self::sampleValues() as $clave => $valor) {
            $texto = str_replace("|{$clave}|", $valor, $texto);
        }

        return $texto;
    }

    /**
     * Catálogo para el formulario: los botones que se pintan sobre el editor.
     *
     * @return list<array{placeholder: string, label: string}>
     */
    public static function catalogo(): array
    {
        $out = [];

        foreach (self::MAPA as $clave => [$_columna, $etiqueta]) {
            $out[] = ['placeholder' => "|{$clave}|", 'label' => $etiqueta];
        }

        return $out;
    }
}
