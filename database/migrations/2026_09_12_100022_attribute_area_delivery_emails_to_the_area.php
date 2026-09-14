<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Los correos de entrega de área se atribuyen al ÁREA, no a la persona que
     * oprimió el botón: a quien los recibe le importa qué área entregó.
     * `delivered_by` pasa a valer "Aplicaciones", "Evaluaciones", "Marketing",
     * "Regulatoria" o "P. Especiales" (lo pone
     * ProjectMailService::sendAreaDelivered), así que la frase del cuerpo se
     * reescribe para leerse bien con el área como sujeto.
     *
     * Se edita SOLO esa frase con str_replace, sin reescribir el template
     * completo: así sobreviven las ediciones hechas desde
     * /settings/email-templates. Si alguien ya cambió la frase a mano, la
     * migración no encuentra el texto y no toca nada.
     */
    private const PHRASES = [
        'project_applications_ready'    => 'entregó las aplicaciones del proyecto',
        'project_applications_updated'  => 'actualizó las aplicaciones del proyecto',
        'project_evaluation_delivered'  => 'entregó las evaluaciones del proyecto',
        'project_evaluation_updated'    => 'actualizó las evaluaciones del proyecto',
        'project_marketing_delivered'   => 'entregó el área de marketing del proyecto',
        'project_marketing_updated'     => 'actualizó el área de marketing del proyecto',
        'project_regulatoria_delivered' => 'entregó el área de regulatoria del proyecto',
        'project_regulatoria_updated'   => 'actualizó el área de regulatoria del proyecto',
        'project_especiales_delivered'  => 'entregó el área de proyectos especiales del proyecto',
        'project_especiales_updated'    => 'actualizó el área de proyectos especiales del proyecto',
    ];

    public function up(): void
    {
        foreach (self::PHRASES as $key => $phrase) {
            $this->rewrite($key, $this->personSubject($phrase), $this->areaSubject($phrase), [
                'delivered_by'  => 'Área que entregó o actualizó (Aplicaciones, Evaluaciones, Marketing, Regulatoria, P. Especiales)',
                'changes_table' => 'Aviso de si las notas de entrega cambiaron (solo actualización)',
            ]);
        }

        // Las tablas de cambios ya no traen columna "Antes": solo cómo quedó
        $this->describeVariables('project_updated', [
            'changes_table' => 'Tabla HTML con los cambios (área, campo, cómo quedó)',
        ]);
        $this->describeVariables('project_development_updated', [
            'changes_table' => 'Aviso de si cambiaron las variantes o las referencias',
        ]);
    }

    public function down(): void
    {
        foreach (self::PHRASES as $key => $phrase) {
            $this->rewrite($key, $this->areaSubject($phrase), $this->personSubject($phrase), [
                'delivered_by'  => 'Usuario que entregó/actualizó el área',
                'changes_table' => 'Tabla HTML con el antes/después de las notas (solo actualización)',
            ]);
        }

        $this->describeVariables('project_updated', [
            'changes_table' => 'Tabla HTML con los cambios (área, campo, antes, después)',
        ]);
        $this->describeVariables('project_development_updated', [
            'changes_table' => 'Tabla HTML con lo que cambió en variantes/referencias',
        ]);
    }

    /** "<strong>|delivered_by|</strong> entregó las evaluaciones del proyecto" */
    private function personSubject(string $phrase): string
    {
        return '<strong>|delivered_by|</strong> ' . $phrase;
    }

    /** "Entrega del área de <strong>|delivered_by|</strong> en el proyecto" */
    private function areaSubject(string $phrase): string
    {
        return (str_starts_with($phrase, 'entregó') ? 'Entrega' : 'Actualización')
            . ' del área de <strong>|delivered_by|</strong> en el proyecto';
    }

    /** Cambia la frase dentro del cuerpo y actualiza las descripciones de variables. */
    private function rewrite(string $key, string $from, string $to, array $variables): void
    {
        $row = DB::table('email_templates')->where('key', $key)->first();

        if (!$row) {
            return;
        }

        DB::table('email_templates')->where('key', $key)->update([
            'header_content'      => str_replace($from, $to, (string) $row->header_content),
            'available_variables' => $this->merged($row->available_variables, $variables),
            'updated_at'          => now(),
        ]);
    }

    private function describeVariables(string $key, array $variables): void
    {
        $row = DB::table('email_templates')->where('key', $key)->first();

        if (!$row) {
            return;
        }

        DB::table('email_templates')->where('key', $key)->update([
            'available_variables' => $this->merged($row->available_variables, $variables),
            'updated_at'          => now(),
        ]);
    }

    /** Conserva las demás variables documentadas del template. */
    private function merged(?string $json, array $variables): string
    {
        $current = json_decode((string) $json, true);

        return (string) json_encode(
            array_merge(is_array($current) ? $current : [], $variables),
            JSON_UNESCAPED_UNICODE
        );
    }
};
