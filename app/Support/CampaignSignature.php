<?php

namespace App\Support;

use App\Models\User;

/**
 * Firma de campañas de correo: cada usuario tiene la suya (users.campaign_signature)
 * y se agrega al final del cuerpo de lo que ese usuario envía.
 */
final class CampaignSignature
{
    /** Agrega la firma del usuario al final del cuerpo (si tiene una). */
    public static function append(string $body, ?User $user): string
    {
        $firma = trim((string) $user?->campaign_signature);
        if ($firma === '') {
            return $body;
        }

        return $body . '<div style="margin-top:24px;">' . $firma . '</div>';
    }

    /** Quita lo que no debe viajar en un correo (scripts, iframes, manejadores on*, javascript:). */
    public static function sanitize(string $html): string
    {
        $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*/?>#i', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1=$2#$2', $html);

        return trim($html);
    }

    /**
     * Plantilla inicial para que cada quien la complete en el editor de texto.
     * Solo usa lo que el editor conserva (texto, negrita, enlaces e imagen).
     */
    public static function plantilla(): string
    {
        return '<p><img src="' . url('/images/logo.png') . '" width="95" alt="Finearom"></p>'
            . '<p><strong>Nombre Apellido</strong><br>Cargo</p>'
            . '<p>&#128222; <a href="tel:3000000000">3000000000</a> - <a href="tel:+576015956613">(601) 5956613 ext 000</a><br>'
            . '&#9993; <a href="mailto:correo@finearom.com">correo@finearom.com</a></p>';
    }
}
