<?php

namespace App\Http\Controllers;

use App\Models\ProjectNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectNotificationController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:project list')->only(['index', 'markRead', 'markAllRead', 'unreadCount']);
    }

    /**
     * Devuelve las notificaciones del usuario autenticado, paginadas.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = ProjectNotification::with('project:id,nombre')
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($notifications);
    }

    /**
     * Retorna el número de notificaciones no leídas del usuario autenticado.
     */
    public function unreadCount(): JsonResponse
    {
        $count = ProjectNotification::unread()
            ->where('user_id', auth()->id())
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Marca una notificación específica como leída.
     */
    public function markRead(ProjectNotification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json(['message' => 'No autorizado'], 403);
        }

        if (is_null($notification->leida_at)) {
            $notification->leida_at = now();
            $notification->save();
        }

        return response()->json(['message' => 'Notificación marcada como leída']);
    }

    /**
     * Marca todas las notificaciones del usuario autenticado como leídas.
     */
    public function markAllRead(): JsonResponse
    {
        ProjectNotification::unread()
            ->where('user_id', auth()->id())
            ->update(['leida_at' => now()]);

        return response()->json(['message' => 'Todas las notificaciones marcadas como leídas']);
    }
}
