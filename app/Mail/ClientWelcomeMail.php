<?php

namespace App\Mail;

use App\Models\Client;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ClientWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public $client;
    public $emailData;
    public $includeAttachments;
    public $internalNotice;

    /**
     * Campos que el cliente no ve en el formulario público porque son de manejo
     * interno. Deben completarse desde la plataforma.
     * Espejo de ClientController::CLIENT_INTERNAL_ONLY_FIELDS.
     */
    private const CAMPOS_INTERNOS = [
        'credit_term' => 'Plazo de crédito',
        'client_type' => 'Tipo de cliente',
        'lead_time'   => 'Lead time (días)',
    ];

    /**
     * @param bool $internalNotice Agrega el aviso de campos internos pendientes.
     *                             Solo para la copia que va al equipo de Finearom,
     *                             nunca para el cliente.
     */
    public function __construct(Client $client, array $emailData = [], bool $includeAttachments = false, bool $internalNotice = false)
    {
        $this->client = $client;
        $this->emailData = $emailData;
        $this->includeAttachments = $includeAttachments;
        $this->internalNotice = $internalNotice;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $service = new EmailTemplateService();
        $variables = $this->prepareVariables();
        $subject = $service->getRenderedSubject('client_welcome', $variables);

        return new Envelope(
            subject: $subject,
            from: config('mail.from.address', 'monica.castano@finearom.com'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $service = new EmailTemplateService();
        $variables = $this->prepareVariables();
        $rendered = $service->renderTemplate('client_welcome', $variables);

        if ($this->internalNotice) {
            $rendered['footer_content'] = $this->buildInternalFieldsNotice()
                . ($rendered['footer_content'] ?? '');
        }

        return new Content(
            view: 'emails.template_centered',
            with: $rendered
        );
    }

    /**
     * Aviso para el equipo de Finearom con los campos que el cliente no puede
     * llenar. Muestra el valor actual de cada uno y marca los que faltan, para
     * que se sepa qué hay que completar sin tener que entrar a revisar.
     */
    private function buildInternalFieldsNotice(): string
    {
        $filas = '';
        $pendientes = 0;

        foreach (self::CAMPOS_INTERNOS as $campo => $etiqueta) {
            $valor = $this->client->{$campo};
            $vacio = $valor === null || $valor === '' || $valor === 0;

            if ($vacio) {
                $pendientes++;
                $celda = '<span style="color:#b45309;font-weight:bold;">Pendiente por completar</span>';
            } else {
                $celda = '<span style="color:#374151;">' . e((string) $valor) . '</span>';
            }

            $filas .= '<tr>'
                . '<td style="padding:6px 12px;border:1px solid #d1d5db;font-size:13px;color:#374151;">' . e($etiqueta) . '</td>'
                . '<td style="padding:6px 12px;border:1px solid #d1d5db;font-size:13px;text-align:right;">' . $celda . '</td>'
                . '</tr>';
        }

        $encabezado = $pendientes > 0
            ? 'Faltan ' . $pendientes . ' de ' . count(self::CAMPOS_INTERNOS) . ' campos internos por completar'
            : 'Campos internos: ya están completos';

        $base = rtrim((string) (config('app.frontend_url') ?? config('app.url')), '/');
        $enlace = $base . '/clients/' . $this->client->id . '/edit';

        return '<div style="margin:20px 0;padding:16px;border:1px solid #fcd34d;background:#fffbeb;border-radius:6px;font-family:Arial,sans-serif;">'
            . '<p style="margin:0 0 6px 0;font-size:14px;font-weight:bold;color:#92400e;">' . $encabezado . '</p>'
            . '<p style="margin:0 0 12px 0;font-size:13px;color:#78350f;">'
            . 'El cliente no ve estos campos en el formulario porque son de manejo interno. '
            . 'Hay que completarlos o corregirlos desde la plataforma.'
            . '</p>'
            . '<table style="width:100%;border-collapse:collapse;margin-bottom:12px;">' . $filas . '</table>'
            . '<a href="' . e($enlace) . '" style="display:inline-block;padding:9px 16px;background:#1F2345;color:#ffffff;'
            . 'text-decoration:none;border-radius:4px;font-size:13px;font-weight:bold;">Completar en la plataforma</a>'
            . '</div>';
    }

    /**
     * Prepara las variables para el template
     */
    protected function prepareVariables(): array
    {
        // Preparar links con mailto y tel
        $executiveEmail = $this->emailData['executive_email'] ?? null;
        $executivePhone = $this->emailData['executive_phone'] ?? null;

        return [
            'client_name' => $this->client->client_name,
            'executive_name' => $this->emailData['executive_name'] ?? 'Equipo Comercial',
            'executive_phone' => $executivePhone ? '<a href="tel:' . $executivePhone . '">' . $executivePhone . '</a>' : '',
            'executive_email' => $executiveEmail ? '<a href="mailto:' . $executiveEmail . '">' . $executiveEmail . '</a>' : '',
            // base_url ya no es necesario, se inyecta automáticamente
        ];
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        if (!$this->includeAttachments) {
            return [];
        }

        $attachments = [];

        $filesToAttach = [
            'rut_file' => 'RUT',
            'camara_comercio_file' => 'Camara_Comercio',
            'cedula_representante_file' => 'Cedula_Representante',
        ];

        if ($this->client->requires_study) {
            $filesToAttach['declaracion_renta_file'] = 'Declaracion_Renta';
            $filesToAttach['estados_financieros_file'] = 'Estados_Financieros';
        }

        foreach ($filesToAttach as $fileField => $fileName) {
            $filePath = $this->client->{$fileField};

            if ($filePath && Storage::disk('public')->exists($filePath)) {
                try {
                    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
                    $cleanClientName = $this->sanitizeFileName($this->client->client_name);

                    $attachments[] = Attachment::fromStorageDisk('public', $filePath)
                        ->as($fileName . '_' . $cleanClientName . '.' . $extension);
                } catch (\Exception $e) {
                    Log::warning("No se pudo adjuntar archivo {$fileField} para cliente {$this->client->client_name}: " . $e->getMessage());
                }
            }
        }

        return $attachments;
    }

    private function sanitizeFileName($name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9\s]/', '', $name);
        $clean = preg_replace('/\s+/', '_', trim($clean));

        return substr($clean, 0, 50);
    }
}
