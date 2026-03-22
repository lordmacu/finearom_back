<?php

namespace App\Http\Controllers;

use App\Services\AITextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIController extends Controller
{
    public function __construct(
        private readonly AITextService $aiService
    ) {}

    /**
     * POST /api/ai/enhance-text
     *
     * Mejora o genera texto usando IA (DeepSeek).
     * Endpoint genérico reutilizable en cualquier módulo.
     *
     * Body:
     *   - context  (string) : propósito del texto (ej: "observaciones de orden de compra al cliente")
     *   - text     (string) : texto actual a mejorar (puede estar vacío)
     *   - extra    (string) : información adicional a incorporar (opcional)
     *   - max_tokens (int) : máximo de tokens de respuesta (default: 500)
     */
    public function enhanceText(Request $request): JsonResponse
    {
        $request->validate([
            'context'    => ['required', 'string', 'max:500'],
            'text'       => ['nullable', 'string', 'max:5000'],
            'extra'      => ['nullable', 'string', 'max:2000'],
            'max_tokens' => ['nullable', 'integer', 'min:100', 'max:2000'],
        ]);

        $context   = $request->input('context');
        $text      = $request->input('text', '');
        $extra     = $request->input('extra', '');
        $maxTokens = $request->input('max_tokens', 500);

        $systemPrompt = "Eres un asistente comercial de Finearom, empresa de fragancias y cosméticos de Colombia.
Tu tarea es mejorar o generar texto de {$context}.
El texto debe ser profesional, cordial y en español colombiano.
No uses emojis, no uses markdown, no uses asteriscos ni símbolos de formato.
Escribe en texto plano. Máximo 3 párrafos cortos y directos.
No incluyas saludos como 'Estimado cliente' ni despedidas como 'Atentamente'.
Ve directo al contenido del mensaje.";

        $userContent = '';

        if (!empty($text)) {
            $userContent .= "Texto actual a mejorar:\n{$text}\n\n";
        }

        if (!empty($extra)) {
            $userContent .= "Información adicional a incorporar en el texto:\n{$extra}\n\n";
        }

        if (empty($text)) {
            $userContent .= "Genera el texto desde cero.";
        } else {
            $userContent .= "Mejora el texto conservando la intención original e incorporando la información adicional si la hay.";
        }

        $result = $this->aiService->enhance($systemPrompt, $userContent, $maxTokens);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Error al generar el texto',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'text'    => $result['text'],
        ]);
    }
}
