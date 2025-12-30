<?php

namespace App\Mail;

use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TemplateMail extends Mailable
{
    use Queueable, SerializesModels;

    protected $templateKey;
    protected $variables;
    protected $customSubject;
    protected $layout;

    /**
     * Create a new message instance.
     *
     * @param string $templateKey Clave del template (ej: 'client_welcome')
     * @param array $variables Variables para reemplazar (ej: ['client_name' => 'Juan'])
     * @param string|null $customSubject Asunto personalizado (opcional, si no se usa el del template)
     * @param string $layout Layout a usar ('template' o 'template_centered')
     */
    public function __construct(
        string $templateKey,
        array $variables = [],
        ?string $customSubject = null,
        string $layout = 'template'
    ) {
        $this->templateKey = $templateKey;
        $this->variables = $variables;
        $this->customSubject = $customSubject;
        $this->layout = $layout;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $service = new EmailTemplateService();
        $rendered = $service->renderTemplate($this->templateKey, $this->variables);

        // Usar subject personalizado o el del template
        $subject = $this->customSubject ?? $rendered['subject'];

        return $this->subject($subject)
            ->view('emails.' . $this->layout, $rendered);
    }
}
