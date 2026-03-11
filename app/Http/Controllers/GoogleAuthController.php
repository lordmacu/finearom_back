<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use App\Services\GoogleDriveService;
use App\Services\GoogleGmailService;
use App\Services\GoogleSheetsService;
use App\Services\GoogleTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleTaskService     $googleService,
        private readonly GoogleDriveService    $driveService,
        private readonly GoogleSheetsService   $sheetsService,
        private readonly GoogleCalendarService $calendarService,
        private readonly GoogleGmailService    $gmailService,
    ) {
        $this->middleware('auth:sanctum')->except(['callback', 'loginUrl']);
        $this->middleware('can:project list')->only(['connectedUsers']);
    }

    /**
     * URL de autorización para LOGIN con Google (sin usuario autenticado).
     * Incluye todos los scopes: Tasks + Drive + Sheets.
     */
    public function loginUrl(Request $request): JsonResponse
    {
        $url = $this->googleService->getLoginUrl($request->query('return_url'));
        return response()->json(['url' => $url]);
    }

    /**
     * Retorna la URL de autorización de Google.
     * El frontend redirige al usuario a esa URL.
     */
    public function authUrl(Request $request): JsonResponse
    {
        $url = $this->googleService->getAuthUrl(
            $request->user()->id,
            $request->query('return_url')
        );

        return response()->json(['url' => $url]);
    }

    /**
     * Callback de Google (ruta web, no API — Google redirige aquí con code + state).
     * Guarda los tokens y redirige al frontend.
     */
    public function callback(Request $request): RedirectResponse
    {
        $frontendUrl = config('app.frontend_url');

        // Si Google envía error (ej: usuario canceló), detectamos si es flujo de login o connect
        if ($request->has('error') || !$request->query('code') || !$request->query('state')) {
            \Illuminate\Support\Facades\Log::warning('[GoogleCallback] Parámetros inválidos o error de Google', [
                'error' => $request->query('error'),
                'has_code' => $request->has('code'),
                'has_state' => $request->has('state'),
            ]);
            // Intentar detectar si era login por el state en caché
            return redirect($frontendUrl . '/login?error=google_login_failed');
        }

        try {
            $result = $this->googleService->handleCallback(
                $request->query('code'),
                $request->query('state')
            );

            if (($result['mode'] ?? 'connect') === 'login') {
                $destination = $result['return_url'] ?? ($frontendUrl . '/login');
                $separator   = str_contains($destination, '?') ? '&' : '?';
                return redirect($destination . $separator . 'auth_token=' . urlencode($result['sanctum_token']) . '&google_connected=1');
            }

            $destination = $result['return_url'] ?? ($frontendUrl . '/settings/google');
            $separator   = str_contains($destination, '?') ? '&' : '?';
            return redirect($destination . $separator . 'connected=1');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[GoogleCallback] Excepción en callback', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            if ($e->getMessage() === 'user_not_found') {
                return redirect($frontendUrl . '/login?error=google_user_not_found');
            }
            if ($e->getMessage() === 'domain_not_allowed') {
                return redirect($frontendUrl . '/login?error=google_domain_not_allowed');
            }
            return redirect($frontendUrl . '/login?error=google_login_failed');
        }
    }

    /**
     * Estado de la conexión Google del usuario actual.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'connected' => $this->googleService->isConnected($request->user()->id),
        ]);
    }

    /**
     * Retorna los usuarios del equipo que tienen Google Tasks conectado.
     * Usado para el selector de asignación en proyectos.
     */
    public function connectedUsers(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->googleService->getConnectedUsers(),
        ]);
    }

    /**
     * URL de autorización que incluye Drive + Sheets además de Tasks.
     */
    public function authUrlFull(Request $request): JsonResponse
    {
        $url = $this->googleService->getAuthUrl(
            $request->user()->id,
            $request->query('return_url'),
            includeDrive: true,
            includeSheets: true,
            includeCalendar: true,
            includeGmail: true,
        );

        return response()->json(['url' => $url]);
    }

    /**
     * Estado extendido: Tasks + Drive + Sheets por separado.
     */
    public function statusExtended(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        return response()->json([
            'connected'         => $this->googleService->isConnected($userId),
            'drive_enabled'     => $this->driveService->hasDriveAccess($userId),
            'sheets_enabled'    => $this->sheetsService->hasSheetsAccess($userId),
            'calendar_enabled'  => $this->calendarService->isConnected($userId),
            'gmail_enabled'     => $this->gmailService->isConnected($userId),
        ]);
    }

    /**
     * Desconecta la cuenta de Google del usuario.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $this->googleService->disconnect($request->user()->id);

        return response()->json(['success' => true, 'message' => 'Cuenta de Google desconectada']);
    }
}
