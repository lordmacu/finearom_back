<?php

namespace App\Support;

/**
 * Texto de un editor enriquecido (CkEditor): "<p>&nbsp;</p>" y similares
 * cuentan como vacío.
 */
class HtmlText
{
    public static function isBlank(?string $html): bool
    {
        $texto = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $texto)) === '';
    }
}
