<?php

namespace App\Mail;

use App\Models\Client;
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
        return $this->subject('Completa tu información - ' . $this->client->client_name)
            ->view('emails.client_autofill');
    }
}

