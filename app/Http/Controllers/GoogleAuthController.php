<?php

namespace App\Http\Controllers;

use App\Services\GoogleDriveService;
use App\Services\GoogleSheetsService;
use App\Services\GoogleTaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly GoogleTaskService  $googleService,
        private readonly GoogleDriveService $driveService,
        private readonly GoogleSheetsService $sheetsService,
    ) {
        $this->middleware('auth:sanctum')->except(['callback']);
        $this->middleware('can:project list')->only(['connectedUsers']);
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

        if ($request->has('error')) {
            return redirect($frontendUrl . '/settings/google?error=access_denied');
        }

        try {
            $result = $this->googleService->handleCallback(
                $request->query('code'),
                $request->query('state')
            );

            $destination = $result['return_url'] ?? ($frontendUrl . '/settings/google');
            // Agregar ?connected=1 a la URL de destino
            $separator = str_contains($destination, '?') ? '&' : '?';
            return redirect($destination . $separator . 'connected=1');
        } catch (\Exception $e) {
            return redirect($frontendUrl . '/settings/google?error=1');
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
            'connected'      => $this->googleService->isConnected($userId),
            'drive_enabled'  => $this->driveService->hasDriveAccess($userId),
            'sheets_enabled' => $this->sheetsService->hasSheetsAccess($userId),
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
