<?php

namespace App\Mail;

use App\Models\Client;
use App\Services\EmailTemplateService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ClientAutofillMail extends Mailable
{
    use Queueable, SerializesModels;

    public Client $client;
    public string $link;

    public function __construct(Client $client, string $link)
    {
        $this->client = $client;
        $this->link = $link;
    }

    public function build(): self
    {
        $service = new EmailTemplateService();
        $variables = [
            'client_name' => $this->client->client_name,
            'link' => $this->link,
        ];

        $rendered = $service->renderTemplate('client_autofill', $variables);
        $subject = $service->getRenderedSubject('client_autofill', $variables);

        return $this->subject($subject)
            ->view('emails.template', $rendered);
    }
}
