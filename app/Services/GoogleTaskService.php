<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserGoogleToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleTaskService
{
    private const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const TASKS_URL = 'https://tasks.googleapis.com/tasks/v1';
    private const SCOPE     = 'https://www.googleapis.com/auth/tasks';

    // ─── OAuth ────────────────────────────────────────────────────────────────

    /**
     * Genera la URL de autorización de Google.
     * Guarda el user_id en caché usando un state aleatorio para recuperarlo en el callback.
     */
    public function getAuthUrl(int $userId, ?string $returnUrl = null): string
    {
        $state = Str::random(40);
        Cache::put("google_oauth_state_{$state}", [
            'user_id'    => $userId,
            'return_url' => $returnUrl,
        ], now()->addMinutes(15));

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',   // fuerza que siempre retorne refresh_token
            'state'         => $state,
        ]);
    }

    /**
     * Procesa el callback de Google: intercambia el code por tokens y los guarda.
     * Retorna el user_id asociado al state.
     */
    /**
     * Procesa el callback de Google: intercambia el code por tokens y los guarda.
     * Retorna un array con user_id y return_url.
     */
    public function handleCallback(string $code, string $state): array
    {
        $cached = Cache::pull("google_oauth_state_{$state}");

        if (!$cached) {
            throw new \RuntimeException('Estado de OAuth inválido o expirado');
        }

        // Compatibilidad hacia atrás: si el cache es solo un int (formato antiguo)
        $userId    = is_array($cached) ? $cached['user_id'] : $cached;
        $returnUrl = is_array($cached) ? ($cached['return_url'] ?? null) : null;

        $response = Http::post(self::TOKEN_URL, [
            'client_id'     => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri'  => config('services.google.redirect'),
            'grant_type'    => 'authorization_code',
            'code'          => $code,
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('Error al obtener tokens de Google: ' . $response->body());
        }

        $tokens = $response->json();

        UserGoogleToken::updateOrCreate(
            ['user_id' => $userId],
            [
                'access_token'  => encrypt($tokens['access_token']),
                'refresh_token' => isset($tokens['refresh_token'])
                    ? encrypt($tokens['refresh_token'])
                    : $this->getExistingRefreshToken($userId),
                'expires_at'    => now()->addSeconds($tokens['expires_in'] - 60),
            ]
        );

        return ['user_id' => $userId, 'return_url' => $returnUrl];
    }

    // ─── Tasks API ────────────────────────────────────────────────────────────

    /**
     * Crea una tarea en la lista por defecto del usuario.
     */
    public function createTask(int $userId, string $title, ?string $notes = null, ?string $dueDate = null): array
    {
        $accessToken = $this->getValidToken($userId);

        $body = ['title' => $title];

        if ($notes) {
            $body['notes'] = $notes;
        }

        if ($dueDate) {
            // Google Tasks requiere RFC 3339 con hora en 00:00:00Z
            $body['due'] = Carbon::parse($dueDate)->startOfDay()->toRfc3339String();
        }

        $response = Http::withToken($accessToken)
            ->post(self::TASKS_URL . '/lists/@default/tasks', $body);

        if ($response->failed()) {
            throw new \RuntimeException('Error al crear tarea en Google Tasks: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Crea una tarea para múltiples usuarios. Los fallos individuales se loguean
     * sin interrumpir el resto.
     */
    public function createTaskForUsers(array $userIds, string $title, ?string $notes = null, ?string $dueDate = null): void
    {
        foreach ($userIds as $userId) {
            try {
                if ($this->isConnected($userId)) {
                    $this->createTask($userId, $title, $notes, $dueDate);
                }
            } catch (\Throwable $e) {
                Log::warning("Google Tasks: fallo al crear tarea para user {$userId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Retorna todos los usuarios que tienen Google Tasks conectado.
     */
    public function getConnectedUsers(): \Illuminate\Support\Collection
    {
        $connectedUserIds = UserGoogleToken::pluck('user_id');
        return User::whereIn('id', $connectedUserIds)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();
    }

    // ─── Estado de conexión ───────────────────────────────────────────────────

    public function isConnected(int $userId): bool
    {
        return UserGoogleToken::where('user_id', $userId)->exists();
    }

    public function disconnect(int $userId): void
    {
        UserGoogleToken::where('user_id', $userId)->delete();
    }

    // ─── Privados ─────────────────────────────────────────────────────────────

    /**
     * Retorna un access token válido, refrescándolo si ya expiró.
     */
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
                // El refresh token expiró o fue revocado — limpiar y pedir reconexión
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

    /**
     * Al re-autorizar, Google no siempre retorna un nuevo refresh_token.
     * Si ya existe uno guardado, lo conservamos.
     */
    private function getExistingRefreshToken(int $userId): ?string
    {
        $existing = UserGoogleToken::where('user_id', $userId)->value('refresh_token');
        return $existing; // ya está encriptado, se guarda tal cual
    }
}
