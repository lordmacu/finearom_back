<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use App\Services\EmailTemplateService;
use App\Helpers\CarteraEmailHelper;
use Carbon\Carbon;

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

    public function envelope(): Envelope
    {
        $service = app(EmailTemplateService::class);
        $subject = $service->getRenderedSubject($this->templateKey(), $this->getVariables());

        return new Envelope(
            subject: $subject ?: 'Finearom - Cartera'
        );
    }

    public function content(): Content
    {
        $service = app(EmailTemplateService::class);
        $html = $service->getRenderedHtml($this->templateKey(), $this->getVariables());

        return new Content(
            htmlString: $html
        );
    }

    private function templateKey(): string
    {
        return $this->emailType === 'order_block' ? 'portfolio_block_alert' : 'portfolio_status';
    }

    private function getVariables(): array
    {
        // Variables comunes
        $variables = [
            'client_name' => $this->dataEmail['client_name'] ?? '',
            'invoices_table' => CarteraEmailHelper::generateInvoicesTable($this->dataEmail['cuentas'] ?? []),
            'balance_info' => CarteraEmailHelper::generateBalanceInfo($this->dataEmail),
        ];

        // Variables específicas según el tipo de email
        if ($this->emailType === 'order_block') {
            $variables['ejecutiva'] = $this->dataEmail['ejecutiva'] ?? 'Cliente';
            $variables['blocked_orders_table'] = CarteraEmailHelper::generateBlockedOrdersTable($this->dataEmail['products'] ?? []);
        } else {
            // portfolio_status
            $variables['previous_year'] = Carbon::now()->subYear()->year;
            $variables['current_year'] = Carbon::now()->year;
        }

        return $variables;
    }

    public function build()
    {
        return $this->withSymfonyMessage(function ($message) {
            $message->getHeaders()->addTextHeader('X-Process-Type', $this->emailType);
            $message->getHeaders()->addTextHeader('X-Metadata', json_encode([
                'client_nit' => $this->dataEmail['nit'] ?? null,
                'client_name' => $this->dataEmail['client_name'] ?? null
            ]));
        });
    }
}
