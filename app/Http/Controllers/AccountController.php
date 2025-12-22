<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use Illuminate\Http\JsonResponse;

class AccountController extends Controller
{
    public function show(): JsonResponse
    {
        $user = auth()->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $user->load('roles:id,name');

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $user->update($request->validated());

        $user->load('roles:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Cuenta actualizada exitosamente',
            'data' => $user,
        ]);
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
            ], 401);
        }

        $user->update([
            'password' => (string) $request->input('new_password'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada exitosamente',
        ]);
    }
}

