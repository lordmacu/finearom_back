<?php

namespace App\Http\Controllers;

use App\Models\OrderGoogleTaskConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderGoogleTaskConfigController extends Controller
{
    private const TRIGGERS = ['on_create', 'on_observation', 'on_dispatch'];

    /**
     * Retorna la configuración global de Google Tasks para órdenes de compra.
     */
    public function index(): JsonResponse
    {
        $configs = OrderGoogleTaskConfig::whereIn('trigger', self::TRIGGERS)->get();

        $result = [];
        foreach (self::TRIGGERS as $trigger) {
            $found = $configs->firstWhere('trigger', $trigger);
            $result[$trigger] = ['user_ids' => $found?->user_ids ?? []];
        }

        return response()->json($result);
    }

    /**
     * Guarda la configuración global de Google Tasks para órdenes de compra.
     * Body: { on_create: { user_ids: [] }, on_observation: { user_ids: [] }, on_dispatch: { user_ids: [] } }
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'on_create.user_ids'      => ['nullable', 'array'],
            'on_observation.user_ids' => ['nullable', 'array'],
            'on_dispatch.user_ids'    => ['nullable', 'array'],
        ]);

        foreach (self::TRIGGERS as $trigger) {
            $userIds = $request->input("{$trigger}.user_ids", []);

            if (empty($userIds)) {
                OrderGoogleTaskConfig::where('trigger', $trigger)->delete();
            } else {
                OrderGoogleTaskConfig::updateOrCreate(
                    ['trigger' => $trigger],
                    ['user_ids' => $userIds]
                );
            }
        }

        return response()->json(['message' => 'Configuración guardada']);
    }
}
