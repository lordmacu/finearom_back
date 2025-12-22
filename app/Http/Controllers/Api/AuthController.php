<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Cache;

class AuthController extends Controller
{
    /**
     * Get user permissions with caching
     */
    private function getUserPermissions($user): array
    {
        // Cache key único por usuario
        $cacheKey = "user.{$user->id}.permissions";
        
        // Caché por 1 hora (3600 segundos)
        return Cache::remember($cacheKey, 3600, function() use ($user) {
            // Spatie Permission - Obtener todos los permisos via roles
            return $user->getAllPermissions()->pluck('name')->toArray();
        });
    }

    /**
     * Get user roles with caching
     */
    private function getUserRoles($user): array
    {
        $cacheKey = "user.{$user->id}.roles";
        
        return Cache::remember($cacheKey, 3600, function() use ($user) {
            return $user->roles->pluck('name')->toArray();
        });
    }
    /**
     * Login de usuario
     */
    public function login(LoginRequest $request)
    {
        // Autenticar usando la lógica del LoginRequest original
        $request->authenticate();

        $user = $request->user();

        // Eliminar tokens anteriores del usuario (opcional)
        $user->tokens()->delete();

        // Crear nuevo token
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $this->getUserRoles($user),
                'permissions' => $this->getUserPermissions($user),
            ],
            'token' => $token,
            'message' => 'Login exitoso'
        ], 200);
    }

    /**
     * Enviar link de recuperación de contraseña
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // Usamos el broker de contraseñas de Laravel
        $status = Password::sendResetLink(
            $request->only('email')
        );

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['message' => 'Enlace de recuperación enviado exitosamente.'], 200)
            : response()->json(['message' => 'No pudimos enviar el enlace a este correo.'], 400);
    }

    /**
     * Resetear la contraseña con el token
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Contraseña restablecida correctamente.'], 200)
            : response()->json(['message' => 'No se pudo restablecer la contraseña.'], 400);
    }

    /**
     * Logout de usuario
     */
    public function logout(Request $request)
    {
        // Eliminar el token actual del usuario
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout exitoso'
        ], 200);
    }

    /**
     * Obtener usuario autenticado
     */
    public function user(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $this->getUserRoles($user),
                'permissions' => $this->getUserPermissions($user),
            ]
        ], 200);
    }
}
