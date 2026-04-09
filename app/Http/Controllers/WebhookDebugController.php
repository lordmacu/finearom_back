<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookDebugController extends Controller
{
    /**
     * Endpoint genérico de debug que loguea todo lo que llega.
     * POST /api/webhook
     */
    public function handle(Request $request)
    {
        Log::channel('daily')->info('[/api/webhook] incoming', [
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $request->headers->all(),
            'query' => $request->query(),
            'payload' => $request->all(),
            'raw_body' => $request->getContent(),
        ]);

        return response()->json([
            'received' => true,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
