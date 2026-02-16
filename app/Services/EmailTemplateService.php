<?php

namespace App\Services;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Cache;

class EmailTemplateService
{
    public static function cacheKey(string $templateKey): string
    {
        return "email_template.{$templateKey}";
    }

    public static function clearCache(string $templateKey): void
    {
        Cache::forget(self::cacheKey($templateKey));
    }

    /**
     * Reemplaza las variables en un texto usando el formato |variable|
     *
     * @param string $content
     * @param array $variables
     * @return string
     */
    public function replaceVariables(string $content, array $variables): string
    {
        // Inyectar variables globales automáticamente
        $globalVariables = $this->getGlobalVariables();
        $allVariables = array_merge($globalVariables, $variables);

        foreach ($allVariables as $key => $value) {
            // Reemplazar |variable| con el valor
            $content = str_replace("|{$key}|", $value ?? '', $content);
        }

        return $content;
    }

    /**
     * Obtiene las variables globales que siempre están disponibles
     *
     * @return array
     */
    protected function getGlobalVariables(): array
    {
        return [
            'base_url' => config('app.url'),
        ];
    }

    /**
     * Renderiza un template completo con las variables proporcionadas
     *
     * @param string $templateKey
     * @param array $variables
     * @return array ['subject' => string, 'title' => string|null, 'header' => string|null, 'footer' => string|null, 'signature' => string|null]
     */
    public function renderTemplate(string $templateKey, array $variables): array
    {
        $template = Cache::remember(self::cacheKey($templateKey), 600, function () use ($templateKey) {
            return EmailTemplate::where('key', $templateKey)
                ->where('is_active', true)
                ->firstOrFail();
        });

        return [
            'subject' => $this->replaceVariables($template->subject, $variables),
            'title' => $template->title ? $this->replaceVariables($template->title, $variables) : null,
            'header_content' => $template->header_content ? $this->replaceVariables($template->header_content, $variables) : null,
            'footer_content' => $template->footer_content ? $this->replaceVariables($template->footer_content, $variables) : null,
            'signature' => $template->signature ? $this->replaceVariables($template->signature, $variables) : null,
        ];
    }

    /**
     * Obtiene el contenido completo del email renderizado como HTML
     * Útil para usar con Symfony Mailer directamente
     *
     * @param string $templateKey
     * @param array $variables
     * @param string $layout Layout a usar ('template' o 'template_centered')
     * @return string HTML completo del email
     */
    public function getRenderedHtml(string $templateKey, array $variables, string $layout = 'template'): string
    {
        $rendered = $this->renderTemplate($templateKey, $variables);

        return view('emails.' . $layout, $rendered)->render();
    }

    /**
     * Obtiene el subject renderizado del template
     *
     * @param string $templateKey
     * @param array $variables
     * @return string
     */
    public function getRenderedSubject(string $templateKey, array $variables): string
    {
        $rendered = $this->renderTemplate($templateKey, $variables);
        return $rendered['subject'];
    }

    /**
     * Prepara las variables para el email, incluyendo wrappers HTML si es necesario
     *
     * @param array $data
     * @return array
     */
    public function prepareVariables(array $data): array
    {
        $prepared = [];

        foreach ($data as $key => $value) {
            // Si el valor es HTML (contiene tags), no modificarlo
            if (is_string($value) && strip_tags($value) !== $value) {
                $prepared[$key] = $value;
            }
            // Si es un string simple, dejarlo tal cual
            elseif (is_string($value)) {
                $prepared[$key] = $value;
            }
            // Si es null, convertir a string vacío
            elseif (is_null($value)) {
                $prepared[$key] = '';
            }
            // Si es otro tipo, convertir a string
            else {
                $prepared[$key] = (string) $value;
            }
        }

        return $prepared;
    }
}
