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

        return $body . '<div style="margin-top:24px;">' . self::paraCorreo($firma) . '</div>';
    }

    private const AZUL = '#2f5fb3';

    /**
     * Le da a la firma el diseño corporativo al enviarla. El editor de texto solo
     * guarda una tabla simple (logo | nombre y cargo | contacto); aquí se le quitan
     * los bordes del correo, se centra verticalmente y se pone la línea azul que
     * separa el contacto y la que cierra la firma. Una firma sin tabla queda igual.
     */
    public static function paraCorreo(string $html): string
    {
        if (stripos($html, '<table') === false) {
            return $html;
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="firma-raiz">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $raiz = $dom->getElementById('firma-raiz');
        if (! $raiz) {
            return $html;
        }

        // <figure class="table"> es el envoltorio del editor; en el correo estorba (márgenes)
        foreach (iterator_to_array($dom->getElementsByTagName('figure')) as $fig) {
            if (! str_contains((string) $fig->getAttribute('class'), 'table')) {
                continue;
            }
            while ($fig->firstChild) {
                $fig->parentNode->insertBefore($fig->firstChild, $fig);
            }
            $fig->parentNode->removeChild($fig);
        }

        $ultimaTabla = null;
        foreach ($dom->getElementsByTagName('table') as $tabla) {
            $ultimaTabla = $tabla;
            $tabla->setAttribute('style', 'border-collapse:collapse;border:none;margin:0;width:auto;');

            foreach ($tabla->getElementsByTagName('tr') as $fila) {
                $celdas = [];
                foreach ($fila->childNodes as $n) {
                    if ($n instanceof \DOMElement && in_array($n->tagName, ['td', 'th'], true)) {
                        $celdas[] = $n;
                    }
                }
                foreach ($celdas as $i => $celda) {
                    $estilo = 'border:none;vertical-align:middle;padding:0 ' . ($i === count($celdas) - 1 ? '0' : '18px') . ' 0 ' . ($i === 0 ? '0' : '18px') . ';';
                    if (count($celdas) > 1 && $i === count($celdas) - 1) {
                        $estilo .= 'border-left:1px solid ' . self::AZUL . ';';
                    }
                    $celda->setAttribute('style', $estilo);
                }
            }
        }

        // línea azul que cierra la firma
        if ($ultimaTabla) {
            $linea = $dom->createElement('div');
            $linea->setAttribute('style', 'border-bottom:1px solid ' . self::AZUL . ';width:60%;margin-top:12px;');
            $ultimaTabla->parentNode->insertBefore($linea, $ultimaTabla->nextSibling);
        }

        $salida = '';
        foreach ($raiz->childNodes as $hijo) {
            $salida .= $dom->saveHTML($hijo);
        }

        return $salida;
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
     * Plantilla inicial: tabla simple (logo | nombre y cargo | contacto) que el editor
     * de texto conserva y que paraCorreo() convierte en el diseño corporativo.
     */
    public static function plantilla(): string
    {
        return '<figure class="table"><table><tbody><tr>'
            . '<td><img src="' . url('/images/logo.png') . '" width="95" alt="Finearom"></td>'
            . '<td><strong>Nombre Apellido</strong><br>Cargo</td>'
            . '<td>&#128222; <a href="tel:3000000000">3000000000</a> - <a href="tel:+576015956613">(601) 5956613 ext 000</a><br>'
            . '&#9993; <a href="mailto:correo@finearom.com">correo@finearom.com</a></td>'
            . '</tr></tbody></table></figure>';
    }
}
