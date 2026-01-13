<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class PurchaseOrderAuthController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    public function sendCode(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $code = (string) random_int(1000, 9999);
        $verificationId = (string) Str::uuid();

        Cache::put($this->cacheKey($verificationId), [
            'code' => $code,
            'email' => $request->email,
        ], now()->addMinutes(self::CODE_TTL_MINUTES));

        $fromAddress = config('mail.from.address');
        $fromName = config('mail.from.name');

        Mail::raw("Your verification code is: {$code}", function ($message) use ($request, $fromAddress, $fromName) {
            $message->to($request->email)->subject('Verification Code');

            if ($fromAddress) {
                $message->from($fromAddress, $fromName);
            }
        });

        return response()->json([
            'verification_id' => $verificationId,
            'expires_in' => self::CODE_TTL_MINUTES * 60,
            'message' => 'Codigo enviado.',
        ]);
    }

    public function verifyCode(Request $request)
    {
        $validated = $request->validate([
            'verification_id' => ['required', 'string'],
            'code' => ['required', 'numeric'],
        ]);

        $payload = Cache::get($this->cacheKey($validated['verification_id']));
        if (! $payload) {
            return response()->json(['message' => 'Codigo expirado o invalido.'], 422);
        }

        if ((string) $validated['code'] !== (string) $payload['code']) {
            return response()->json(['message' => 'Codigo invalido.'], 422);
        }

        $user = User::where('email', $payload['email'])->first();
        if (! $user) {
            return response()->json(['message' => 'Email no encontrado.'], 404);
        }

        $client = Client::where('user_id', $user->id)->first();
        if (! $client) {
            return response()->json(['message' => 'Cliente no encontrado para este usuario.'], 404);
        }

        $user->tokens()->where('name', 'purchase-order-portal')->delete();
        $token = $user->createToken('purchase-order-portal')->plainTextToken;

        Cache::forget($this->cacheKey($validated['verification_id']));

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'client_id' => $client->id,
            ],
            'message' => 'Verificacion exitosa.',
        ]);
    }

    private function cacheKey(string $verificationId): string
    {
        return "purchase_order_verification:{$verificationId}";
    }
}
