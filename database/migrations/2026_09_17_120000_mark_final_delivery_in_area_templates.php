<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Con entregas parciales, el correo de la entrega de cada área tiene que
     * decir que es la FINAL (y la actualización, que es posterior a ella). Los
     * correos van como "Re:" del hilo, así que el tipo se lee en el título y
     * en la primera frase. Solo se tocan templates con el texto original: los
     * editados desde /settings/email-templates se respetan.
     */
    private const AREAS = [
        // key delivered, título original, key updated, título original, nombre
        ['applications_ready',    'Aplicaciones listas',     'applications_updated', 'Aplicaciones actualizadas', 'Aplicaciones'],
        ['evaluation_delivered',  'Evaluaciones listas',     'evaluation_updated',   'Evaluaciones actualizadas', 'Evaluaciones'],
        ['marketing_delivered',   'Marketing entregado',     'marketing_updated',    'Marketing actualizado',     'Marketing'],
        ['regulatoria_delivered', 'Regulatoria entregada',   'regulatoria_updated',  'Regulatoria actualizada',   'Regulatoria'],
        ['especiales_delivered',  'P. Especiales entregado', 'especiales_updated',   'P. Especiales actualizada', 'P. Especiales'],
    ];

    private const FRASE_FINAL = ['Entrega del área de', '<strong>Entrega final</strong> del área de'];
    private const FRASE_UPDATE = ['Actualización del área de', 'Actualización (posterior a la entrega final) del área de'];

    public function up(): void
    {
        foreach (self::AREAS as [$final, $tituloFinal, $update, $tituloUpdate, $nombre]) {
            $this->cambiar("project_{$final}", $tituloFinal, "{$nombre}: entrega final", self::FRASE_FINAL);
            $this->cambiar("project_{$update}", $tituloUpdate, "{$nombre}: actualización después de la entrega final", self::FRASE_UPDATE);
        }

        // Variable disponible para quien edite los templates de entrega
        DB::table('email_templates')
            ->where(fn ($q) => $q->where('key', 'like', 'project_%_delivered')
                ->orWhere('key', 'like', 'project_%_updated')
                ->orWhere('key', 'like', 'project_%_partial')
                ->orWhere('key', 'project_applications_ready'))
            ->whereNotIn('key', ['project_development_delivered', 'project_development_updated'])
            ->get(['id', 'available_variables'])
            ->each(function ($t) {
                $vars = json_decode((string) $t->available_variables, true) ?: [];
                $vars['tipo_entrega'] = 'Tipo de entrega: Entrega parcial, Entrega final o Actualización';
                DB::table('email_templates')->where('id', $t->id)->update(['available_variables' => json_encode($vars)]);
            });
    }

    public function down(): void
    {
        foreach (self::AREAS as [$final, $tituloFinal, $update, $tituloUpdate, $nombre]) {
            $this->cambiar("project_{$final}", "{$nombre}: entrega final", $tituloFinal, array_reverse(self::FRASE_FINAL));
            $this->cambiar("project_{$update}", "{$nombre}: actualización después de la entrega final", $tituloUpdate, array_reverse(self::FRASE_UPDATE));
        }
    }

    private function cambiar(string $key, string $tituloActual, string $tituloNuevo, array $frase): void
    {
        $template = DB::table('email_templates')->where('key', $key)->where('title', $tituloActual)->first();
        if (!$template) {
            return;
        }

        DB::table('email_templates')->where('id', $template->id)->update([
            'title'          => $tituloNuevo,
            'header_content' => str_replace($frase[0], $frase[1], (string) $template->header_content),
            'updated_at'     => now(),
        ]);
    }
};
