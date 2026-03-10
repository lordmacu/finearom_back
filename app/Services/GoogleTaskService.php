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
    private const AUTH_URL      = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL     = 'https://oauth2.googleapis.com/token';
    private const TASKS_URL     = 'https://tasks.googleapis.com/tasks/v1';
    private const SCOPE_TASKS   = 'https://www.googleapis.com/auth/tasks';
    private const SCOPE_DRIVE   = 'https://www.googleapis.com/auth/drive.file';
    private const SCOPE_SHEETS  = 'https://www.googleapis.com/auth/spreadsheets';

    /** @deprecated Use SCOPE_TASKS */
    private const SCOPE = 'https://www.googleapis.com/auth/tasks';

    // ─── OAuth ────────────────────────────────────────────────────────────────

    /**
     * Genera la URL de autorización de Google para LOGIN (flujo sin usuario autenticado).
     * Incluye todos los scopes: Tasks + Drive + Sheets.
     */
    public function getLoginUrl(?string $returnUrl = null): string
    {
        $state = Str::random(40);
        Cache::put("google_oauth_state_{$state}", [
            'mode'       => 'login',
            'return_url' => $returnUrl,
        ], now()->addMinutes(15));

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => implode(' ', [self::SCOPE_TASKS, self::SCOPE_DRIVE, self::SCOPE_SHEETS]),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    /**
     * Genera la URL de autorización de Google.
     * Guarda el user_id en caché usando un state aleatorio para recuperarlo en el callback.
     */
    public function getAuthUrl(int $userId, ?string $returnUrl = null, bool $includeDrive = false, bool $includeSheets = false): string
    {
        $state = Str::random(40);
        Cache::put("google_oauth_state_{$state}", [
            'user_id'    => $userId,
            'return_url' => $returnUrl,
        ], now()->addMinutes(15));

        $scopes = [self::SCOPE_TASKS];
        if ($includeDrive) {
            $scopes[] = self::SCOPE_DRIVE;
        }
        if ($includeSheets) {
            $scopes[] = self::SCOPE_SHEETS;
        }

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => config('services.google.client_id'),
            'redirect_uri'  => config('services.google.redirect'),
            'response_type' => 'code',
            'scope'         => implode(' ', $scopes),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    /**
     * Procesa el callback de Google: intercambia el code por tokens y los guarda.
     * Soporta dos modos:
     *   - 'connect': usuario ya autenticado, vincula su cuenta de Google.
     *   - 'login': sin usuario autenticado, busca user por email y crea token Sanctum.
     *
     * Retorna array con mode, user_id, return_url y (si login) sanctum_token.
     */
    public function handleCallback(string $code, string $state): array
    {
        $cached = Cache::pull("google_oauth_state_{$state}");

        if (!$cached) {
            throw new \RuntimeException('Estado de OAuth inválido o expirado');
        }

        // Compatibilidad hacia atrás: si el cache es solo un int (formato antiguo)
        $mode      = is_array($cached) ? ($cached['mode'] ?? 'connect') : 'connect';
        $userId    = is_array($cached) ? ($cached['user_id'] ?? null) : $cached;
        $returnUrl = is_array($cached) ? ($cached['return_url'] ?? null) : null;

        $tokens = $this->exchangeCode($code);

        if ($mode === 'login') {
            $userInfo = $this->getGoogleUserInfo($tokens['access_token']);
            $email = $userInfo['email'] ?? '';

            // Solo se permite login con cuentas @finearom.com
            if (!str_ends_with($email, '@finearom.com')) {
                throw new \RuntimeException('domain_not_allowed');
            }

            $user = User::where('email', $email)->first();

            if (!$user) {
                throw new \RuntimeException('user_not_found');
            }

            $userId = $user->id;
            $this->saveTokens($userId, $tokens);

            // Revocar tokens previos y crear uno nuevo
            $user->tokens()->delete();
            $sanctumToken = $user->createToken('api-token')->plainTextToken;

            return [
                'mode'          => 'login',
                'user_id'       => $userId,
                'return_url'    => $returnUrl,
                'sanctum_token' => $sanctumToken,
            ];
        }

        $this->saveTokens($userId, $tokens);

        return ['mode' => 'connect', 'user_id' => $userId, 'return_url' => $returnUrl];
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    private function exchangeCode(string $code): array
    {
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

        return $response->json();
    }

    private function saveTokens(int $userId, array $tokens): void
    {
        $grantedScopes = isset($tokens['scope'])
            ? explode(' ', $tokens['scope'])
            : [self::SCOPE_TASKS];

        UserGoogleToken::updateOrCreate(
            ['user_id' => $userId],
            [
                'access_token'  => encrypt($tokens['access_token']),
                'refresh_token' => isset($tokens['refresh_token'])
                    ? encrypt($tokens['refresh_token'])
                    : $this->getExistingRefreshToken($userId),
                'expires_at'    => now()->addSeconds($tokens['expires_in'] - 60),
                'scopes'        => $grantedScopes,
            ]
        );
    }

    private function getGoogleUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get('https://www.googleapis.com/oauth2/v1/userinfo');

        if ($response->failed()) {
            throw new \RuntimeException('No se pudo obtener la información del usuario de Google');
        }

        return $response->json();
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
