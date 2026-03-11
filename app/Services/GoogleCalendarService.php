<?php

namespace App\Services;

use App\Models\UserGoogleToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    private const CALENDAR_URL = 'https://www.googleapis.com/calendar/v3';
    private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';

    /**
     * Crea un evento en el calendario primario del usuario.
     *
     * @param int         $userId     ID del usuario con Google conectado
     * @param string      $title      Título del evento
     * @param Carbon      $start      Inicio del evento
     * @param Carbon|null $end        Fin del evento (por defecto 1 hora después)
     * @param string|null $description Descripción / notas
     * @param string|null $location   Lugar del evento
     * @param array       $attendees  Emails de asistentes [['email' => '...']]
     * @return array{id: string, htmlLink: string}
     */
    public function createEvent(
        int $userId,
        string $title,
        Carbon $start,
        ?Carbon $end = null,
        ?string $description = null,
        ?string $location = null,
        array $attendees = []
    ): array {
        $accessToken = $this->getValidToken($userId);

        $end = $end ?? $start->copy()->addHour();

        $body = [
            'summary'     => $title,
            'start'       => ['dateTime' => $start->toRfc3339String(), 'timeZone' => 'America/Bogota'],
            'end'         => ['dateTime' => $end->toRfc3339String(),   'timeZone' => 'America/Bogota'],
        ];

        if ($description) {
            $body['description'] = $description;
        }

        if ($location) {
            $body['location'] = $location;
        }

        if (!empty($attendees)) {
            $body['attendees'] = $attendees;
        }

        $response = Http::withToken($accessToken)
            ->post(self::CALENDAR_URL . '/calendars/primary/events', $body);

        if ($response->failed()) {
            Log::warning('[GoogleCalendar] Error al crear evento', [
                'user_id' => $userId,
                'status'  => $response->status(),
                'body'    => $response->body(),
            ]);
            throw new \RuntimeException('Error al crear evento en Google Calendar: ' . $response->body());
        }

        $event = $response->json();

        return [
            'id'       => $event['id'],
            'htmlLink' => $event['htmlLink'],
        ];
    }

    /**
     * Elimina un evento del calendario primario del usuario.
     */
    public function deleteEvent(int $userId, string $eventId): void
    {
        $accessToken = $this->getValidToken($userId);

        $response = Http::withToken($accessToken)
            ->delete(self::CALENDAR_URL . "/calendars/primary/events/{$eventId}");

        if ($response->failed() && $response->status() !== 410) {
            // 410 Gone = ya fue eliminado en Google, ignoramos
            Log::warning('[GoogleCalendar] Error al eliminar evento', [
                'user_id'  => $userId,
                'event_id' => $eventId,
                'status'   => $response->status(),
            ]);
        }
    }

    /**
     * Verifica si el usuario tiene el scope de Calendar autorizado.
     */
    public function isConnected(int $userId): bool
    {
        $token = UserGoogleToken::where('user_id', $userId)->first();
        if (!$token || !$token->scopes) {
            return false;
        }
        return in_array('https://www.googleapis.com/auth/calendar', $token->scopes);
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

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
