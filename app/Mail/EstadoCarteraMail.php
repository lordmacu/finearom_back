<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class EstadoCarteraMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $dataEmail;
    public string $emailType;

    public function __construct(array $dataEmail, string $emailType)
    {
        $this->dataEmail = $dataEmail;
        $this->emailType = $emailType;
    }

    public function build(): self
    {
        $view = $this->emailType === 'order_block' ? 'admin.bloqueo' : 'admin.estadocartera';
        $subject = $this->emailType === 'order_block'
            ? 'Finearom- Alerta de bloqueo: ' . ($this->dataEmail['client_name'] ?? '')
            : 'Finearom - Estado de cartera: ' . ($this->dataEmail['client_name'] ?? '');

        return $this->subject($subject)
            ->view($view, ['cartera' => $this->dataEmail])
            ->withSymfonyMessage(function ($message) {
                $message->getHeaders()->addTextHeader('X-Process-Type', $this->emailType);
                $message->getHeaders()->addTextHeader('X-Metadata', json_encode([
                    'client_nit' => $this->dataEmail['nit'] ?? null,
                    'client_name' => $this->dataEmail['client_name'] ?? null
                ]));
            });
    }
}

