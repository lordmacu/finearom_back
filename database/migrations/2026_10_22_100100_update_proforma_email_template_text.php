<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ORIGINAL = 'para su revisión y aprobación.';
    private const NUEVO = 'para su revisión y programación de pago.';
    private const NOTA = '<p><strong>Importante:</strong> esta proforma tiene una validez de <strong>8 días hábiles</strong> a partir de su emisión. Pasado ese plazo, los precios y la TRM deberán actualizarse.</p>';

    /**
     * Texto del correo de proforma: "revisión y programación de pago" + nota que
     * resalta la validez. Solo si la plantilla conserva el texto original (no pisa
     * ediciones hechas desde la interfaz).
     */
    public function up(): void
    {
        $t = DB::table('email_templates')->where('key', 'proforma')->first();
        if (! $t || ! str_contains((string) $t->footer_content, self::ORIGINAL)) {
            return;
        }

        $footer = str_replace(self::ORIGINAL, self::NUEVO, $t->footer_content);
        if (! str_contains($footer, '8 días hábiles')) {
            // La nota va justo después del primer párrafo (el de la proforma adjunta)
            $footer = preg_replace('/<\/p>/', '</p>' . self::NOTA, $footer, 1);
        }

        DB::table('email_templates')->where('key', 'proforma')->update(['footer_content' => $footer]);
    }

    public function down(): void
    {
        $t = DB::table('email_templates')->where('key', 'proforma')->first();
        if (! $t) {
            return;
        }
        $footer = str_replace([self::NUEVO, self::NOTA], [self::ORIGINAL, ''], $t->footer_content);
        DB::table('email_templates')->where('key', 'proforma')->update(['footer_content' => $footer]);
    }
};
