<?php

namespace App\Console\Commands;

use App\Mail\ClientWelcomeMail;
use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendClientWelcome extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:client-welcome 
        {client_id : ID del cliente} 
        {--to= : Email destino (opcional, por defecto el email del cliente)} 
        {--with-attachments : Incluir adjuntos del cliente (RUT, cámara de comercio, etc.)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envía el correo de bienvenida (client_welcome) a un cliente para pruebas';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $clientId = (int) $this->argument('client_id');
        $overrideTo = $this->option('to');
        $includeAttachments = (bool) $this->option('with-attachments');

        $client = Client::find($clientId);

        if (! $client) {
            $this->error("Cliente {$clientId} no encontrado.");
            return self::FAILURE;
        }

        $toEmail = $overrideTo ?: $client->email ?: $client->executive_email;
        if (empty($toEmail)) {
            $this->error('No hay correo destino (cliente y ejecutivo sin email). Usa --to para forzar uno.');
            return self::FAILURE;
        }

        $emailData = [
            'executive_name'  => $client->executive ?? $client->executive_email,
            'executive_email' => $client->executive_email,
            'executive_phone' => $client->executive_phone,
            'welcome_date'    => now()->format('d/m/Y'),
        ];

        Mail::to($toEmail)->send(new ClientWelcomeMail($client, $emailData, $includeAttachments));

        $this->info("Correo de bienvenida enviado a {$toEmail} para cliente {$client->client_name} (ID {$client->id}). Adjuntos: " . ($includeAttachments ? 'sí' : 'no'));

        return self::SUCCESS;
    }
}
