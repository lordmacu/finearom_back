<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio genérico de mejora de texto con IA (DeepSeek).
 * Reutilizable en cualquier módulo del sistema.
 */
class AITextService
{
    private const API_URL = 'https://api.deepseek.com/chat/completions';
    private const MODEL   = 'deepseek-chat';
    private const TIMEOUT = 30;

    public function enhance(string $systemPrompt, string $userContent, int $maxTokens = 600): array
    {
        $key = config('custom.deepseek_api_key');

        if (empty($key)) {
            return ['success' => false, 'text' => '', 'error' => 'API key no configurada'];
        }

        try {
            $resp = Http::withHeaders([
                    'Authorization' => "Bearer {$key}",
                    'Content-Type'  => 'application/json',
                ])
                ->timeout(self::TIMEOUT)
                ->post(self::API_URL, [
                    'model'      => self::MODEL,
                    'max_tokens' => $maxTokens,
                    'messages'   => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userContent],
                    ],
                ]);

            if (!$resp->successful()) {
                Log::error('[AITextService] Error API DeepSeek: ' . $resp->body());
                return ['success' => false, 'text' => '', 'error' => 'Error en la API de IA'];
            }

            $text = trim($resp->json('choices.0.message.content', ''));

            return ['success' => true, 'text' => $text];

        } catch (\Throwable $e) {
            Log::error('[AITextService] Excepción: ' . $e->getMessage());
            return ['success' => false, 'text' => '', 'error' => $e->getMessage()];
        }
    }
}
