<?php

namespace App\Services;

use App\Models\UserGoogleToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleGmailService
{
    private const GMAIL_URL  = 'https://gmail.googleapis.com/gmail/v1/users/me';
    private const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
    private const SCOPE_GMAIL = 'https://mail.google.com/';

    /**
     * Crea un borrador en el Gmail del usuario.
     *
     * @param int         $userId  ID del usuario con Gmail conectado
     * @param string      $subject Asunto del correo
     * @param string      $body    Cuerpo en texto plano
     * @param string|null $to      Destinatario (opcional — el usuario lo agrega en Gmail)
     * @return array{id: string, webLink: string}
     */
    public function createDraft(int $userId, string $subject, string $body, ?string $to = null): array
    {
        $accessToken = $this->getValidToken($userId);

        $mime = $this->buildMime($subject, $body, $to);
        $raw  = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

        $response = Http::withToken($accessToken)
            ->post(self::GMAIL_URL . '/drafts', [
                'message' => ['raw' => $raw],
            ]);

        if ($response->failed()) {
            Log::warning('[GoogleGmail] Error al crear borrador', [
                'user_id' => $userId,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
            throw new \RuntimeException('Error al crear borrador en Gmail: ' . $response->body());
        }

        $draft = $response->json();

        return [
            'id'      => $draft['id'],
            'webLink' => 'https://mail.google.com/mail/u/0/#drafts/' . $draft['id'],
        ];
    }

    /**
     * Verifica si el usuario tiene el scope de Gmail autorizado.
     */
    public function isConnected(int $userId): bool
    {
        $token = UserGoogleToken::where('user_id', $userId)->first();
        if (!$token || !$token->scopes) {
            return false;
        }
        return in_array(self::SCOPE_GMAIL, $token->scopes);
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    private function buildMime(string $subject, string $body, ?string $to): string
    {
        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';

        if ($to) {
            $headers[] = 'To: ' . $to;
        }

        return implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode($body);
    }

    private function getValidToken(int $userId): string
    {
        $token = UserGoogleToken::where('user_id', $userId)->firstOrFail();

        if ($token->expires_at->isPast()) {
            $response = Http::post(self::TOKEN_URL, [
                'client_id'     => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => decrypt($token->refresh_token),
                'grant_type'    => 'refresh_token',
            ]);

            if ($response->failed()) {
                $token->delete();
                throw new \RuntimeException('La sesión de Google expiró. Por favor reconecta tu cuenta.');
            }

            $tokens = $response->json();
            $token->update([
                'access_token' => encrypt($tokens['access_token']),
                'expires_at'   => now()->addSeconds($tokens['expires_in'] - 60),
            ]);

            return $tokens['access_token'];
        }

        return decrypt($token->access_token);
    }
}
