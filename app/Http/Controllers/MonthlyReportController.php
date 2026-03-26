<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\PurchaseOrder;
use App\Services\DhlService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MonthlyReportController extends Controller
{
    /**
     * Exporta el reporte mensual como JSON con:
     *   - ordenes: órdenes despachadas (completed + parcial_status) con sus productos embebidos
     *   - stats:   estadísticas del dashboard para el mismo período
     *
     * Parámetros opcionales:
     *   - start_date (Y-m-d)  — por defecto: inicio del mes actual
     *   - end_date   (Y-m-d)  — por defecto: fin del mes actual
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();

        try {
            return response()->json([
                'success' => true,
                'period'  => ['start_date' => $startDate, 'end_date' => $endDate],
                'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
                'stats'   => $this->buildStats($startDate, $endDate),
            ]);
        } catch (\Throwable $e) {
            Log::error('MonthlyReport error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error generando reporte'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Chat IA sobre el reporte guardado
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Inicia una nueva conversación con la IA usando el reporte guardado como contexto.
     * Devuelve el thread_id para continuar la conversación.
     */
    public function chatStart(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();
        $period    = ['start_date' => $startDate, 'end_date' => $endDate];

        $report = [
            'period'      => $period,
            'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
            'stats'       => $this->buildStats($startDate, $endDate),
        ];

        $now   = Carbon::now('America/Bogota');
        $today = $now->toDateString();

        // TRM de hoy (o la más reciente disponible) — para conversiones de cartera
        $trmHoy = DB::table('trm_daily')
            ->where('date', '<=', $today)
            ->orderByDesc('date')
            ->value('value');
        $trmHoyStr = $trmHoy ? number_format((float)$trmHoy, 2) : '4000.00';

        $prompt = "Eres un asistente de análisis comercial para Finearom. " .
                  "Te comparto el reporte mensual del período {$period['start_date']} al {$period['end_date']}. " .
                  "Responde todas las preguntas de forma clara y concisa en español. " .
                  "IMPORTANTE: Formatea SIEMPRE tus respuestas en HTML limpio (sin ```html ni backticks). " .
                  "Usa <p>, <strong>, <ul>, <li>, <table>, <tr>, <td>, <th> según corresponda. " .
                  "Para números destacados usa <strong>. Para listas usa <ul><li>. Para tablas usa <table> con clases inline básicas. " .
                  "Cuando el usuario haga una pregunta, respóndela directamente sin repetir el contexto.\n" .
                  "Fecha y hora actual (Colombia): {$now->format('Y-m-d H:i:s')} — úsala como referencia para calcular rangos relativos (hoy, esta semana, hace N días, etc.).\n" .
                  "TRM de hoy ({$today}): \${$trmHoyStr} COP/USD — úsala SOLO para convertir valores de cartera a USD si te lo piden. Las órdenes tienen su propia TRM individual y no deben usar esta.\n\n" .

                  "NOTAS CLAVE — lee antes de responder:\n" .
                  "- 'Total OC' = valor de las órdenes que tuvieron AL MENOS UN despacho real (dispatch_date) en el período — igual al dashboard\n" .
                  "- 'Despachado / Facturado' = dinero ya enviado al cliente (suma de partials reales del período). 'Pendiente' = Total OC − Facturado\n" .
                  "- 'Planeado' = OC con fecha de despacho programada dentro del período (subconjunto del total, NO igual al total)\n" .
                  "- Cumplimiento% puede superar el 100%: es normal porque los despachos del período pueden incluir OC creadas en meses anteriores\n" .
                  "- ESTADÍSTICAS POR EJECUTIVA es la fuente de verdad para preguntas por ejecutiva — NO sumar desde el detalle de órdenes\n" .
                  "- La columna '$/kg USD' en ESTADÍSTICAS POR EJECUTIVA es el precio promedio ponderado por kilo en USD — úsala directamente, NO calcules sumando órdenes del detalle\n" .
                  "- La columna 'Valor USD' en ESTADÍSTICAS POR EJECUTIVA es la fuente de verdad para el valor en USD de cada ejecutiva\n" .
                  "- 'Kilos despachados totales' (en RESUMEN FINANCIERO) = fuente de verdad para kilos totales del período\n" .
                  "- 'Líneas de producto despachadas' = número de ítems distintos, NO es kilos\n" .
                  "- En TOP PRODUCTOS, la columna 'Kilos despachados' = kilos reales (no unidades)\n" .
                  "- Productos marcados como (muestra) tienen precio $0 — no cuentan en totales financieros\n" .
                  "- En DETALLE DE ÓRDENES: estado pending = sin despachar, processing = en proceso, parcial_status = despacho parcial, completed = despachada totalmente\n" .
                  "- En DETALLE DE ÓRDENES, el Valor mostrado corresponde a lo despachado real (partials), no al valor total de la orden\n" .
                  "- En DETALLE DE ÓRDENES, el header de cada OC incluye la ejecutiva y los kilos totales (excluyendo muestras). Para verificar kilos de una ejecutiva, suma los kg del header de sus OCs — el resultado debe coincidir con la columna 'Kilos' de ESTADÍSTICAS POR EJECUTIVA\n" .
                  "- CARTERA y RECAUDOS son en PESOS COLOMBIANOS (COP). Para convertir a USD usar la TRM de hoy indicada arriba\n" .
                  "- NUNCA usar la TRM de hoy para convertir valores de órdenes — cada OC tiene su propia TRM\n" .
                  "- Cumplimiento / efectividad del período = (total despachado USD) / (total OC USD) × 100. Cuando alguien pregunte por el porcentaje de efectividad, cumplimiento o relación entre creadas y facturadas, SIEMPRE calcula y responde directamente con el número — NUNCA expliques la fórmula sin dar el resultado. Si se pregunta cuánto sería el cumplimiento si todo lo planeado se despacha, sumar el planeado + lo ya despachado y dividir entre el total OC\n" .
                  "- Cuando se pregunte por el producto [NEW WIN] con mayor o menor precio/kg, listar TODOS los productos marcados [NEW WIN] en DETALLE DE ÓRDENES sin excepción (busca todas las ocurrencias de [NEW WIN] en el detalle), compara sus precios unitarios y selecciona el extremo correcto\n" .
                  "- El 'valor de una OC' siempre es el campo 'Valor: X USD / Y COP' en el encabezado de la orden — NUNCA sumes solo las líneas de un producto específico dentro de ella (eso da el valor de ese producto, no de la orden). Ejemplo: si preguntan cuánto valen dos OCs que contienen BOUSCHET III, la respuesta es la suma de los campos Valor de esas dos OCs, no la suma de las líneas de BOUSCHET III\n" .
                  "- RESUMEN POR CLIENTE es la fuente de verdad para totales por cliente (OCs, USD, kilos) — NO sumes desde el detalle\n" .
                  "- TOP 10 PRODUCTOS PEDIDOS = los productos más demandados en las OCs del período (no despachados)\n" .
                  "- NEW WIN DEL PERÍODO = tabla de todos los productos marcados [NEW WIN], ordenados por precio/kg descendente\n\n" .

                  "REPORTE:\n" . ($md = $this->buildReportMarkdown($report)) . "\n\n" .

                  "ESQUEMA DE BASE DE DATOS:\n" . $this->getDbSchema() . "\n\n" .

                  "INSTRUCCIONES PARA QUERIES SQL:\n" .
                  "- SIEMPRE que el usuario pida una lista, ranking, tabla o detalle de datos (top N órdenes, clientes, productos, ejecutivas, despachos, cartera, etc.), genera una query SQL MariaDB lista para ejecutar — incluso si el dato está en el reporte precompilado. El usuario quiere verlo en tabla interactiva.\n" .
                  "- Solo responde sin query cuando la pregunta es un cálculo simple (ej: '¿cuánto es el cumplimiento?') o una explicación conceptual.\n" .
                  "- Presenta SIEMPRE la query así (HTML exacto, sin variaciones): <pre><code class=\"language-sql\">QUERY_SQL_AQUI</code></pre>\n" .
                  "- Antes del bloque SQL, escribe una línea breve diciendo qué muestra la consulta.\n" .
                  "- Las queries deben usar las tablas del ESQUEMA DE BASE DE DATOS proporcionado. Usa el período {$period['start_date']} a {$period['end_date']} como filtro cuando sea relevante.\n" .
                  "- PUEDES y DEBES usar CTEs (WITH ... AS (...) SELECT ...) para queries complejas con múltiples pasos — el sistema las acepta y ejecuta correctamente.\n" .
                  "- PUEDES usar window functions (SUM OVER, ROW_NUMBER OVER PARTITION BY) para rankings y acumulados.\n\n" .

                  "Confirma que recibiste el reporte con un mensaje breve de bienvenida (2-3 líneas) " .
                  "indicando el período, el total de órdenes creadas (valor USD), y cuánto se despachó/facturó realmente en USD.";

        Storage::disk('local')->put('monthly_report.md', $md);

        $periodLabel = Carbon::parse($startDate)->locale('es')->isoFormat('MMMM YYYY');

        try {
            $resp = Http::withHeaders(['X-Api-Key' => $aiKey])
                ->timeout(60)
                ->post("{$aiUrl}/v1/chat/completions", [
                    'model'    => 'gpt-4.1',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$resp->successful()) {
                Log::error('[Chat] Error en chatStart: ' . $resp->body());
                return response()->json(['success' => false, 'message' => 'Error conectando con IA'], 500);
            }

            $threadId      = $resp->json('thread_id');
            $welcomeMsg    = trim($resp->json('choices.0.message.content', 'Listo, puedes hacerme preguntas sobre el reporte.'));
            $now           = now()->toDateTimeString();

            $session = ChatSession::create([
                'user_id'      => auth()->id(),
                'thread_id'    => $threadId,
                'period_label' => ucfirst($periodLabel),
                'period_start' => $startDate,
                'period_end'   => $endDate,
                'messages'     => [
                    ['role' => 'assistant', 'content' => $welcomeMsg, 'time' => $now],
                ],
            ]);

            return response()->json([
                'success'    => true,
                'session_id' => $session->id,
                'thread_id'  => $threadId,
                'message'    => $welcomeMsg,
                'period'     => $period,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Chat] chatStart exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Inicia una nueva conversación con la IA usando un prompt ligero (sin reporte precompilado).
     * Envía solo el schema de DB, contexto de negocio e instrucciones de métricas.
     * Devuelve el thread_id para continuar la conversación.
     */
    public function chatStartV2(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
            'model'      => 'nullable|string|in:gpt-4.1,claude-sonnet-4-5,deepseek-chat',
        ]);

        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();
        $period    = ['start_date' => $startDate, 'end_date' => $endDate];

        $now   = Carbon::now('America/Bogota');
        $today = $now->toDateString();

        // TRM de hoy (o la más reciente disponible)
        $trmHoy = DB::table('trm_daily')
            ->where('date', '<=', $today)
            ->orderByDesc('date')
            ->value('value');
        $trmHoyStr = $trmHoy ? number_format((float)$trmHoy, 2) : '4000.00';

        $periodContext = $this->buildPeriodContext($startDate, $endDate, $today, $trmHoyStr);

        $periodLabel = Carbon::parse($startDate)->locale('es')->isoFormat('MMMM YYYY');

        $model = $request->get('model', 'gpt-4.1');

        if ($limitResponse = $this->checkAndIncrementModelLimit($model)) {
            return $limitResponse;
        }

        // === DEEPSEEK PATH ===
        if (str_starts_with($model, 'deepseek')) {
            try {
                $result = $this->callDeepSeekApi([
                    ['role' => 'system', 'content' => $this->buildSystemPrompt()],
                    ['role' => 'user',   'content' => $periodContext],
                ]);

                if (!$result['success']) {
                    return response()->json(['success' => false, 'message' => 'Error conectando con DeepSeek'], 500);
                }

                $threadId   = (string) \Illuminate\Support\Str::uuid();
                $welcomeMsg = $result['content'] ?: 'Listo, puedes hacerme preguntas sobre el período.';
                $now        = now()->toDateTimeString();

                $session = ChatSession::create([
                    'user_id'      => auth()->id(),
                    'thread_id'    => $threadId,
                    'period_label' => ucfirst($periodLabel),
                    'period_start' => $startDate,
                    'period_end'   => $endDate,
                    'messages'     => [
                        ['role' => 'assistant', 'content' => $welcomeMsg, 'time' => $now],
                    ],
                ]);

                return response()->json([
                    'success'    => true,
                    'session_id' => $session->id,
                    'thread_id'  => $threadId,
                    'message'    => $welcomeMsg,
                    'period'     => $period,
                ]);
            } catch (\Throwable $e) {
                Log::error('[Chat][DeepSeek] chatStartV2 exception: ' . $e->getMessage());
                return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
            }
        }

        // === NODE PROXY PATH (servidor Node intermedio) ===
        /*
        try {
            $resp = Http::withHeaders(['X-Api-Key' => $aiKey])
                ->timeout(60)
                ->post("{$aiUrl}/v1/chat/completions", [
                    'model'    => $model,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$resp->successful()) {
                Log::error('[Chat] Error en chatStartV2: ' . $resp->body());
                return response()->json(['success' => false, 'message' => 'Error conectando con IA'], 500);
            }

            $threadId   = $resp->json('thread_id');
            $welcomeMsg = trim($resp->json('choices.0.message.content', 'Listo, puedes hacerme preguntas sobre el período.'));
            $now        = now()->toDateTimeString();

            $session = ChatSession::create([
                'user_id'      => auth()->id(),
                'thread_id'    => $threadId,
                'period_label' => ucfirst($periodLabel),
                'period_start' => $startDate,
                'period_end'   => $endDate,
                'messages'     => [
                    ['role' => 'assistant', 'content' => $welcomeMsg, 'time' => $now],
                ],
            ]);

            return response()->json([
                'success'    => true,
                'session_id' => $session->id,
                'thread_id'  => $threadId,
                'message'    => $welcomeMsg,
                'period'     => $period,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Chat] chatStartV2 exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
        */

        return response()->json(['success' => false, 'message' => 'Modelo no soportado en este endpoint'], 422);
    }

    /**
     * Envía un mensaje del usuario al hilo existente y devuelve la respuesta de la IA.
     */
    public function chatMessage(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $request->validate([
            'thread_id'  => 'required|string',
            'message'    => 'required|string|max:2000',
            'session_id' => 'nullable|integer',
            'model'      => 'nullable|string|in:gpt-4.1,claude-sonnet-4-5,deepseek-chat',
        ]);

        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        // Cargar sesión para obtener el período real
        $session = null;
        $periodStart = 'INICIO';
        $periodEnd   = 'FIN';
        if ($request->session_id) {
            $session = ChatSession::where('id', $request->session_id)
                ->where('user_id', auth()->id())
                ->first();
            if ($session) {
                $periodStart = $session->period_start?->toDateString() ?? 'INICIO';
                $periodEnd   = $session->period_end?->toDateString()   ?? 'FIN';
            }
        }


        $model = $request->get('model', 'gpt-4.1');

        if ($limitResponse = $this->checkAndIncrementModelLimit($model)) {
            return $limitResponse;
        }

        // === DEEPSEEK PATH (streaming SSE) ===
        if (str_starts_with($model, 'deepseek')) {
            // Reconstruir historial saltando mensajes hidden (prompt inicial de sesiones antiguas)
            $sessionMessages = [];
            if ($session) {
                foreach ($session->messages as $msg) {
                    if (!empty($msg['hidden'])) continue;
                    $role = $msg['role'] === 'assistant' ? 'assistant' : 'user';
                    $sessionMessages[] = ['role' => $role, 'content' => $msg['content']];
                }
            }

            // Truncar historial: mantener últimos 20 mensajes
            if (count($sessionMessages) > 20) {
                $sessionMessages = array_slice($sessionMessages, -20);
            }

            // TRM actual para el contexto de período
            $trmNow    = DB::table('trm_daily')->where('date', '<=', now('America/Bogota')->toDateString())->orderByDesc('date')->value('value');
            $trmNowStr = $trmNow ? number_format((float)$trmNow, 2) : '4000.00';

            // Detectar número de guía DHL e inyectar datos de tracking en tiempo real
            $userMessage    = $request->message;
            $dhlContext     = $this->fetchDhlContextForMessage($userMessage);
            $messageForApi  = $dhlContext
                ? $userMessage . "\n\n[DATOS DE SEGUIMIENTO DHL — consultados en tiempo real]:\n" . $dhlContext
                : $userMessage;

            // System (estático/cacheable) + período + historial + nuevo mensaje
            $apiMessages = array_merge(
                [
                    ['role' => 'system', 'content' => $this->buildSystemPrompt()],
                    ['role' => 'user',   'content' => $this->buildPeriodContext($periodStart, $periodEnd, now('America/Bogota')->toDateString(), $trmNowStr)],
                ],
                $sessionMessages,
                [['role' => 'user', 'content' => $messageForApi]]
            );

            $key        = config('custom.deepseek_api_key');
            $sessionRef = $session;
            $payload    = json_encode([
                'model'       => 'deepseek-chat',
                'messages'    => $apiMessages,
                'temperature' => 0.1,
                'max_tokens'  => 3000,
                'stream'      => true,
            ]);

            return response()->stream(function () use ($key, $payload, $sessionRef, $userMessage) {
                // Limpiar output buffering para que echo llegue al cliente inmediatamente
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }

                $fullContent = '';
                $lineBuffer  = '';

                // Usamos curl directo porque su WRITEFUNCTION dispara en tiempo real,
                // a diferencia de Guzzle que puede bufferear el body completo en PHP-FPM.
                $ch = curl_init('https://api.deepseek.com/chat/completions');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $key,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_TIMEOUT        => 120,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$fullContent, &$lineBuffer) {
                        $lineBuffer .= $chunk;

                        while (($pos = strpos($lineBuffer, "\n")) !== false) {
                            $line       = trim(substr($lineBuffer, 0, $pos));
                            $lineBuffer = substr($lineBuffer, $pos + 1);

                            if (!str_starts_with($line, 'data: ')) continue;

                            $data = substr($line, 6);
                            if ($data === '[DONE]') continue; // se maneja al finalizar curl_exec

                            $json  = json_decode($data, true);
                            $token = $json['choices'][0]['delta']['content'] ?? '';

                            if ($token !== '') {
                                $fullContent .= $token;
                                echo "data: " . json_encode(['token' => $token]) . "\n\n";
                                flush();
                            }
                        }

                        return strlen($chunk); // obligatorio para que curl no aborte
                    },
                ]);

                curl_exec($ch);
                curl_close($ch);

                // Guardar en sesión al terminar
                if ($sessionRef && $fullContent) {
                    $now  = now()->toDateTimeString();
                    $msgs = $sessionRef->messages ?? [];
                    $msgs[] = ['role' => 'user',      'content' => $userMessage,  'time' => $now];
                    $msgs[] = ['role' => 'assistant', 'content' => $fullContent,  'time' => $now];
                    $sessionRef->update(['messages' => $msgs]);
                }

                // done con content como safety net
                echo "data: " . json_encode(['done' => true, 'content' => $fullContent]) . "\n\n";
                flush();
            }, 200, [
                'Content-Type'      => 'text/event-stream',
                'Cache-Control'     => 'no-cache',
                'X-Accel-Buffering' => 'no',
                'Connection'        => 'keep-alive',
            ]);
        }

        // === NODE PROXY PATH (servidor Node intermedio) ===
        /*
        try {
            $resp = Http::withHeaders(['X-Api-Key' => $aiKey])
                ->timeout(120)
                ->post("{$aiUrl}/v1/chat/completions", [
                    'model'     => $model,
                    'messages'  => [['role' => 'user', 'content' => $schemaHint . $request->message]],
                    'thread_id' => $request->thread_id,
                ]);

            if (!$resp->successful()) {
                Log::error('[Chat] Error en chatMessage: ' . $resp->body());
                return response()->json(['success' => false, 'message' => 'Error en IA'], 500);
            }

            $aiMessage = trim($resp->json('choices.0.message.content', ''));
            $now       = now()->toDateTimeString();

            if ($session) {
                $messages   = $session->messages ?? [];
                $messages[] = ['role' => 'user',      'content' => $request->message, 'time' => $now];
                $messages[] = ['role' => 'assistant', 'content' => $aiMessage,         'time' => $now];
                $session->update(['messages' => $messages]);
            }

            return response()->json([
                'success'   => true,
                'message'   => $aiMessage,
                'thread_id' => $resp->json('thread_id'),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Chat] chatMessage exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
        */

        return response()->json(['success' => false, 'message' => 'Modelo no soportado en este endpoint'], 422);
    }

    /**
     * Lista las últimas sesiones de chat del usuario autenticado.
     */
    public function chatSessions(): JsonResponse
    {
        $sessions = ChatSession::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'period_label', 'period_start', 'period_end', 'thread_id', 'created_at', 'messages'])
            ->map(fn($s) => [
                'id'            => $s->id,
                'period_label'  => $s->period_label,
                'period_start'  => $s->period_start?->toDateString(),
                'period_end'    => $s->period_end?->toDateString(),
                'thread_id'     => $s->thread_id,
                'created_at'    => $s->created_at->toDateTimeString(),
                'message_count' => count($s->messages ?? []),
            ]);

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    /**
     * Carga los mensajes de una sesión específica.
     */
    public function chatSessionMessages(ChatSession $session): JsonResponse
    {
        abort_if($session->user_id !== auth()->id(), 403);

        return response()->json([
            'success'  => true,
            'session'  => [
                'id'           => $session->id,
                'period_label' => $session->period_label,
                'period_start' => $session->period_start?->toDateString(),
                'period_end'   => $session->period_end?->toDateString(),
                'thread_id'    => $session->thread_id,
                'messages'     => $session->messages ?? [],
            ],
        ]);
    }

    /**
     * Elimina una sesión de chat del usuario autenticado.
     */
    public function chatSessionDelete(ChatSession $session): JsonResponse
    {
        abort_if($session->user_id !== auth()->id(), 403);
        $session->delete();
        return response()->json(['success' => true]);
    }

    /**
     * Elimina todas las sesiones de chat del usuario autenticado.
     */
    public function chatSessionDeleteAll(): JsonResponse
    {
        ChatSession::where('user_id', auth()->id())->delete();
        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DeepSeek API (llamada directa, sin servidor Node intermedio)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retorna el prompt de sistema estático para DeepSeek (sin variables dinámicas de período/TRM).
     * Al ser idéntico en cada llamada, DeepSeek lo cachea automáticamente (prefix caching).
     */
    private function buildSystemPrompt(): string
    {
        return
            "Eres un asistente de análisis comercial para Finearom, empresa colombiana que comercializa fragancias y materias primas para la industria cosmética.\n\n" .
            "ROLES Y FLUJO DE ÓRDENES:\n" .
            "EJECUTIVAS (Monica Castano, Juliana Pardo, Claudia Cueter, Maria Ortega, Camila Quintero, Daniela Aristizabal): negocian pedidos. Campo clients.executive guarda su email.\n" .
            "FRANCY (pedidos): crea la OC → status='pending'. Marca is_muestra (orden sin costo) y is_new_win (cliente/producto nuevo).\n" .
            "MARLON (logística): revisa pending → agrega observaciones (new_observation=email al cliente, internal_observation=nota interna) → pone fechas estimadas de despacho por producto (partials type='temporal') → OC pasa a status='processing'. OC processing >7 días sin despachar = REZAGADA.\n" .
            "ALEXA (facturación/despacho): cuando la mercancía sale físicamente registra despachos reales → crea partials type='real' (dispatch_date, invoice_number, tracking_number, transporter, trm, quantity) → OC pasa a 'parcial_status' (despachó parte) o 'completed' (despachó todo). Una OC puede tener múltiples partials type='real' en cuotas.\n" .
            "partials type='temporal'=fecha estimada por Marlon | type='real'=despacho real por Alexa (FUENTE DE VERDAD de lo facturado/despachado).\n" .
            "ESTADOS: pending→processing→parcial_status→completed. cancelled: solo Francy o Marlon, excluir de métricas.\n\n" .
            "MAPEO DE TÉRMINOS EN ESPAÑOL → VALORES REALES EN BD:\n" .
            "Cuando el usuario use estos términos, usa el valor exacto del campo status:\n" .
            "- 'pendiente', 'pendientes', 'creada' → 'pending'\n" .
            "- 'procesando', 'en proceso', 'procesado', 'en procesamiento' → 'processing'\n" .
            "- 'parcial', 'despacho parcial', 'parcialmente despachada' → 'parcial_status'\n" .
            "- 'completada', 'completa', 'entregada', 'despachada' → 'completed'\n" .
            "- 'cancelada', 'cancelado', 'anulada' → 'cancelled'\n\n" .
            "CUÁNDO NO FILTRAR POR FECHA DE CREACIÓN (regla crítica):\n" .
            "El filtro order_creation_date BETWEEN solo aplica cuando el usuario pregunta por órdenes CREADAS en un período específico.\n" .
            "⚠ NUNCA uses order_creation_date BETWEEN cuando el filtro principal es por STATUS — las órdenes activas pueden ser de cualquier fecha:\n" .
            "- WHERE po.status = 'processing' → SIN filtro de fecha de creación\n" .
            "- WHERE po.status = 'parcial_status' → SIN filtro de fecha de creación\n" .
            "- WHERE po.status IN ('processing','parcial_status') → SIN filtro de fecha de creación\n" .
            "- WHERE po.status = 'pending' → SIN filtro de fecha de creación\n" .
            "- Órdenes 'estancadas', 'sin despacho', 'sin movimiento' → filtra por status + DATEDIFF(CURDATE(), po.order_creation_date) > X\n" .
            "- Pipeline (órdenes pending o processing sin completar) → sin filtro de fecha de creación\n" .
            "  → Ejemplo estancadas: WHERE po.status='processing' AND par.id IS NULL AND DATEDIFF(CURDATE(), po.order_creation_date) > 15\n" .
            "  → Ejemplo kilos pendientes: WHERE po.status='parcial_status' (sin BETWEEN)\n\n" .
            "CÓMO SABER QUÉ QUEDA PENDIENTE EN UNA OC (parcial_status):\n" .
            "- Kilos pedidos = SUM(pop.quantity) de purchase_order_product WHERE muestra=0\n" .
            "- Kilos despachados = SUM(par.quantity) de partials WHERE type='real' AND deleted_at IS NULL\n" .
            "- Kilos pendientes = kilos_pedidos - kilos_despachados (si > 0 hay mercancía sin despachar)\n" .
            "- Una OC pasa a 'completed' cuando Alexa despacha el resto y no quedan kilos pendientes\n\n" .
            "COMPORTAMIENTO CRÍTICO DE PARTIALS — SIEMPRE SOFT DELETE + REEMPLAZO:\n" .
            "- Cuando Alexa registra o actualiza un despacho: el sistema BORRA (soft delete) TODOS los partials type='real' existentes de esa OC y crea los nuevos desde cero.\n" .
            "  → Los partials anteriores quedan con deleted_at != NULL (son historial, no despachos vigentes)\n" .
            "  → Sin el filtro deleted_at IS NULL estarías contando despachos duplicados/obsoletos\n" .
            "- Cuando Marlon actualiza fechas estimadas: el sistema BORRA (soft delete) TODOS los partials type='temporal' y crea los nuevos.\n" .
            "  → Mismo patrón exacto que los reales\n" .
            "- REGLA ABSOLUTA: en CUALQUIER query sobre partials SIEMPRE incluir: AND par.deleted_at IS NULL\n" .
            "- ⚠ INFLACIÓN DE KILOS PEDIDOS — REGLA CRÍTICA: NUNCA calcules SUM(pop.quantity) en el mismo CTE que itera sobre partials. Una línea de OC puede tener N partials reales (despachos en cuotas) → pop.quantity se cuenta N veces.\n" .
            "  PATRÓN CORRECTO: dos CTEs completamente independientes:\n" .
            "  → CTE 1 arranca desde partials → calcula SOLO kilos/valor despachados (SUM(par.quantity))\n" .
            "  → CTE 2 arranca desde purchase_order_product → calcula SOLO kilos pedidos (SUM(pop.quantity)), filtrando por OCs que tuvieron despachos usando EXISTS\n" .
            "  → Al final se unen los dos CTEs por client_id (o el nivel de agrupación requerido)\n" .
            "  Ejemplo:\n" .
            "  kilos_pedidos AS (SELECT po.client_id, SUM(pop.quantity) kilos_ped FROM purchase_orders po\n" .
            "    JOIN purchase_order_product pop ON pop.purchase_order_id=po.id AND pop.muestra=0\n" .
            "    WHERE EXISTS (SELECT 1 FROM partials par WHERE par.order_id=po.id AND par.type='real' AND par.deleted_at IS NULL AND par.dispatch_date BETWEEN fechas)\n" .
            "    GROUP BY po.client_id)\n" .
            "  → Este patrón elimina la inflación porque pop nunca se une directamente a partials.\n\n" .
            "MÉTRICAS CLAVE DEL DASHBOARD (cómo calcularlas con SQL):\n" .
            "- \"Órdenes DESPACHADAS en el período\" = OCs con AL MENOS UN partial type='real' y dispatch_date en el período → usar EXISTS o JOIN por partials.dispatch_date\n" .
            "  → SIEMPRE incluir: par.type='real' AND par.dispatch_date BETWEEN fechas AND par.deleted_at IS NULL AND pop.muestra=0\n" .
            "  → NO usar order_creation_date para contar órdenes despachadas\n" .
            "- \"Órdenes CREADAS en el período\" = OCs donde order_creation_date BETWEEN fechas → NO usar partials, filtrar directo en purchase_orders\n" .
            "- \"Stats por ejecutiva (pedido vs despachado)\" — PATRÓN CORRECTO:\n" .
            "  → Empieza SIEMPRE desde partials con dispatch_date en el período\n" .
            "  → SUM(pop.quantity) = kilos pedidos (denominador) | SUM(par.quantity) = kilos despachados (numerador)\n" .
            "  → Agrupa por c.executive. NUNCA filtres por order_creation_date — perderías ejecutivas con OCs creadas antes del período\n" .
            "- \"Valor total OC\" = SUM(pop.quantity * precio) — usa pop.quantity (kilos PEDIDOS) para el denominador del cumplimiento\n" .
            "  ⚠ EXCEPCIÓN A LA REGLA GENERAL: aquí usa pop.quantity, NO par.quantity\n" .
            "  → SIEMPRE necesita: JOIN products p ON pop.product_id=p.id\n" .
            "- \"Despachado/Facturado\" = SUM(par.quantity * precio) — usa par.quantity (kilos REALES DESPACHADOS)\n" .
            "- \"Cumplimiento %\" = despachado_usd / total_oc_usd × 100. Calculado desde partials (NO desde order_creation_date)\n" .
            "- \"Fill Rate (tasa de llenado)\" = SUM(par.quantity) / SUM(pop.quantity) × 100 (en kilos). Mide qué porcentaje de lo pedido se despachó.\n" .
            "- \"Valor COP\" = valor_usd × TRM_normalizada. Cascada: partial.trm → po.trm → trm_daily → 4000\n" .
            "  → El +0 es necesario porque trm es string en MariaDB\n" .
            "  → ⚠ NORMALIZACIÓN TRM OBLIGATORIA: algunos partials tienen trm almacenada ×100 (ej: 370005 en lugar de 3700.05). SIEMPRE validar rango:\n" .
            "     CASE WHEN NULLIF(par.trm+0,0) BETWEEN 3400 AND 10000 THEN NULLIF(par.trm+0,0)\n" .
            "          WHEN NULLIF(par.trm+0,0) > 10000 THEN NULLIF(par.trm+0,0)/100\n" .
            "          ELSE NULL END\n" .
            "     Aplicar el mismo patrón a po.trm. Fallback final: trm_daily → 4000\n" .
            "- \"Kilos despachados\" = SUM(par.quantity) de partials reales del período (sin muestras)\n" .
            "- \"Pipeline / Pendiente por facturar\" = SUM de partials type='temporal' activos (deleted_at IS NULL). Valor aún no despachado pero programado.\n" .
            "- \"New Win (nivel OC)\" = COUNT(DISTINCT CASE WHEN po.is_new_win=1 THEN po.id END) — NUNCA SUM(is_new_win) sin DISTINCT, contaría líneas no órdenes\n" .
            "- \"New Win rate\" = new_win_orders / total_orders_creadas × 100\n" .
            "- \"New Win (nivel línea)\" = COUNT(DISTINCT CASE WHEN pop.new_win=1 THEN pop.id END)\n" .
            "- \"Días de atraso vs fecha estimada\" = GREATEST(DATEDIFF(CURDATE(), fecha_estimada), 0) — SIEMPRE usar GREATEST para evitar valores negativos cuando la fecha estimada es futura\n" .
            "- \"Muestra (nivel OC)\" = po.is_muestra=1 → toda la OC es muestra (excluir de totales)\n" .
            "- \"Muestra (nivel línea)\" = pop.muestra=1 → solo esa línea es muestra (excluir esa línea de totales)\n" .
            "  → En consultas de valor/kilos SIEMPRE filtrar: AND pop.muestra=0\n" .
            "- \"Órdenes rezagadas\" = po.status='processing' AND order_creation_date <= CURDATE() - 7 días → órdenes que Marlon aprobó pero Alexa aún no despacha\n" .
            "- \"Planeado del período\" = OCs cuya fecha de despacho estimada cae en el rango. Prioridad de fecha: 1) partial real dispatch_date, 2) partial temporal dispatch_date, 3) order_creation_date + 10 días hábiles como fallback. Este fallback incluye OCs sin ningún partial.\n" .
            "REGLA: si el usuario dice 'creadas', 'del período', 'de este mes' → usa order_creation_date en purchase_orders.\n" .
            "Si dice 'despachadas', 'facturadas', 'enviadas' → usa partials.dispatch_date con type='real' AND deleted_at IS NULL.\n\n" .
            "LEAD TIME Y TIPOS DE CLIENTE:\n" .
            "- client_type tiene DOS clasificaciones distintas:\n" .
            "  * Clasificación comercial: 'AA' (mayor prioridad) > 'A' > 'B' > 'C' (menor prioridad) — se usa para lead time\n" .
            "  * Clasificación portafolio (legado): 'pareto' (estratégico), 'balance', 'none'\n" .
            "- clients.lead_time (INT, días) = tiempo de entrega estándar del cliente (AA/A = 9 días, C = 12 días)\n" .
            "- Lead time real de una OC = DATEDIFF(fecha_primer_despacho_real, po.order_creation_date)\n" .
            "- On-time = el despacho real ocurrió en o antes de la fecha acordada de entrega\n" .
            "  → SIEMPRE usar pop.delivery_date (en purchase_order_product) — es la fecha acordada por línea, la más precisa para on-time\n" .
            "  → po.required_delivery_date es la fecha que pidió originalmente el cliente, suele ser anterior al despacho real, NO usar para on-time\n" .
            "  → ⚠ NUNCA uses pop.required_delivery_date — ese campo NO EXISTE en purchase_order_product\n" .
            "- Para análisis de lead time usar: JOIN con c.client_type IN ('AA','A','B','C')\n\n" .
            "CARTERA — FORMATO Y LÓGICA:\n" .
            "- saldo_contable y saldo_vencido son strings con PUNTO como separador decimal (ej: '26857379.12', '-256309.65'). Ya son decimales normales.\n" .
            "- Para operar numéricamente: CAST(saldo_contable AS DECIMAL(15,2)) — sin REPLACE, el punto es el decimal.\n" .
            "- NUNCA uses REPLACE para quitar puntos — destruyes el separador decimal y multiplicas el valor por 10.\n" .
            "- Para ordenar: ORDER BY CAST(saldo_contable AS DECIMAL(15,2)) DESC\n" .
            "- dias: POSITIVO = factura por vencer, NEGATIVO = factura VENCIDA hace |dias| días\n" .
            "- Facturas vencidas: WHERE dias < 0 — para días de mora usar ABS(ca.dias). NUNCA DATEDIFF(CURDATE(), fecha_cartera).\n" .
            "- ⚠ SIEMPRE filtrar: fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera) — sin esto traes todos los snapshots históricos y repites miles de filas.\n" .
            "- cartera.documento = número de factura → se cruza con recaudos.numero_factura para ver pagos\n" .
            "- Deuda neta real = saldo_contable MENOS lo pagado: MAX(saldo - SUM(recaudos.valor_cancelado), 0)\n" .
            "- catera_type: 'nacional' | 'internacional' (para filtrar cartera de exportación vs local)\n" .
            "- ⚠ CRÍTICO: cartera.vendedor y cartera.nombre_vendedor son el vendedor en SIIGO (ERP) y pueden NO coincidir con clients.executive.\n" .
            "  → Para filtrar cartera por ejecutiva: usa JOIN cartera ca ON ca.nit = clients.nit y filtra por clients.executive, NO por ca.vendedor.\n" .
            "- recaudos.nit es BIGINT (número sin texto). clients.nit y cartera.nit son VARCHAR. El JOIN funciona con conversión implícita en MariaDB.\n" .
            "- ⚠ CRÍTICO GROUP BY en cartera: la tabla cartera tiene UNA FILA POR FACTURA (no por cliente).\n" .
            "  → Si agrupas por nit/cliente, DEBES usar SUM(CAST(saldo_contable AS DECIMAL(15,2))) — nunca saldo_contable directo en SELECT.\n" .
            "  → Si muestras detalle por factura (sin GROUP BY), puedes usar CAST(saldo_contable AS DECIMAL(15,2)) directo.\n" .
            "  → En MariaDB ONLY_FULL_GROUP_BY, todo campo no-agregado en SELECT debe estar en GROUP BY.\n" .
            "- ⚠ CRÍTICO JOIN cartera con despachos: NUNCA hagas JOIN directo de cartera a una query que ya tiene GROUP BY de despachos.\n" .
            "- ⚠ CRÍTICO CARTERA EN SCORECARD POR EJECUTIVA: la cartera vencida debe incluir TODOS los clientes de la ejecutiva, NO solo los que tuvieron despachos en el período.\n" .
            "  → Un cliente puede tener cartera vencida aunque no haya despachado en marzo. Si filtras por partials, esos clientes quedan fuera y la cartera aparece como 0.\n" .
            "  → Para cartera vencida por ejecutiva, SIEMPRE usa subconsulta correlacionada. NUNCA un CTE que agrupe por c.executive — ese campo es sucio y no hace match con executives.email.\n" .
            "  → Cuando la base del query es clients (c): AND cc.executive = c.executive\n" .
            "  → Cuando la base es executives (e): AND cc.executive COLLATE utf8mb4_unicode_ci = e.email\n" .
            "     (\n" .
            "         SELECT COALESCE(SUM(CAST(ca.saldo_vencido AS DECIMAL(15,2))), 0)\n" .
            "         FROM cartera ca JOIN clients cc ON cc.nit = ca.nit\n" .
            "         WHERE ca.fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)\n" .
            "           AND ca.dias < 0\n" .
            "           AND cc.executive COLLATE utf8mb4_unicode_ci = e.email\n" .
            "     ) AS cartera_vencida_cop\n" .
            "  → NUNCA uses SUM(DISTINCT ca.saldo_vencido) ni LEFT JOIN de cartera al flujo principal de despachos para calcular cartera por ejecutiva.\n\n" .
            "REGLA CRÍTICA DE CANTIDADES:\n" .
            "Cuando JOIN partials → purchase_order_product, SIEMPRE usa par.quantity para calcular valor despachado.\n" .
            "pop.quantity es la cantidad PEDIDA, par.quantity es la cantidad REAL DESPACHADA.\n\n" .
            "EJECUTIVA: clients.executive puede ser email (ej: monica.castano@finearom.com).\n" .
            "Para mostrar como nombre: REPLACE(SUBSTRING_INDEX(c.executive,'@',1),'.',' ') AS ejecutiva\n" .
            "Siempre GROUP BY c.executive (no por el alias).\n\n" .
            "ESQUEMA DE BASE DE DATOS:\n" . $this->getDbSchema() . "\n\n" .
            "JOINS ESPECIALES:\n" .
            "- Ciudad de entrega / sucursal: LEFT JOIN branch_offices bo ON pop.branch_office_id = bo.id → bo.delivery_city, bo.name\n" .
            "- Guías del período: SELECT DISTINCT tracking_number, transporter FROM partials WHERE type='real' AND deleted_at IS NULL AND dispatch_date BETWEEN fechas\n" .
            "- Facturas del período: SELECT DISTINCT invoice_number FROM partials WHERE type='real' AND deleted_at IS NULL AND dispatch_date BETWEEN fechas\n\n" .
            "FORMATO DE RESPUESTA — OBLIGATORIO:\n" .
            "Responde SIEMPRE con un objeto JSON válido, sin texto antes ni después, sin wrapper de backticks.\n" .
            "⚠ CRÍTICO PARA STREAMING: el campo \"html\" DEBE ser SIEMPRE el PRIMER campo del JSON — nunca muevas \"sql\" al inicio.\n" .
            "Estructura exacta (orden fijo e inamovible):\n" .
            "{\"html\":\"<p>explicación breve en HTML</p>\",\"sql\":\"SELECT ...\",\"showing\":\"campo1, campo2\",\"available\":\"campo3, campo4\"}\n\n" .
            "Reglas del JSON:\n" .
            "- \"html\": explicación en HTML limpio. Sin LaTeX, sin markdown, sin backticks. Para preguntas sin datos: solo el html, sql=null.\n" .
            "- \"sql\": la query SQL completa, sin escapar. null si no hay query.\n" .
            "- \"showing\": campos que muestra la query, en español legible (ej: 'total órdenes, kilos pedidos, valor USD'). Omitir si sql=null.\n" .
            "- \"available\": campos adicionales que el usuario puede pedir. Omitir si sql=null.\n" .
            "- Usa nombres en español en showing/available. NUNCA nombres de columna de BD.\n" .
            "  order_consecutive→número de OC | dispatch_date→fecha de despacho | executive→ejecutiva | quantity→kilos | price→precio USD/kg\n\n" .
            "REGLAS SQL:\n" .
            "- Usa siempre las fechas del período activo en los filtros.\n" .
            "- ALIASES recomendados: client_name, ejecutiva, kilos, valor_usd, valor_cop, ocs, fill_rate_pct, pipeline_usd, fecha_despacho, numero_oc.\n" .
            "- GROUP BY (MariaDB ONLY_FULL_GROUP_BY): todos los campos no-agregados del SELECT deben estar en GROUP BY. Agrupa por c.executive (no alias).\n" .
            "- NUNCA incluyas columnas id en SELECT. Siempre incluye NIT junto al nombre del cliente.\n" .
            "- ⚠ CLIENTES NUEVOS: cuando el usuario pida 'clientes nuevos', 'first order este año/mes', 'clientes que entraron este año' — NUNCA uses HAVING MIN(po.order_creation_date) >= fecha sobre OCs filtradas por status.\n" .
            "  → Ese patrón falla: un cliente con órdenes completadas en años anteriores pasa el filtro porque sus OCs viejas no tienen status activo.\n" .
            "  → PATRÓN CORRECTO: verificar la primera orden en TODA la historia del cliente con subconsulta:\n" .
            "     HAVING (SELECT MIN(po_all.order_creation_date) FROM purchase_orders po_all WHERE po_all.client_id = c.id) >= 'FECHA_INICIO'\n" .
            "  → Esto garantiza que el cliente no tiene ninguna orden anterior a esa fecha en todo el sistema.\n\n" .
            "- El mensaje de bienvenida: html con saludo breve + período activo + 3-4 ejemplos de preguntas. sql=null.\n" .
            "- ⚠ GROUP_CONCAT para comparar pertenencia entre conjuntos: NUNCA uses GROUP_CONCAT + IS NULL/NOT NULL para detectar si un elemento específico estuvo en un período anterior. GROUP_CONCAT devuelve un string — IS NULL solo verifica si el grupo existe, no si el elemento está dentro.\n" .
            "  → MAL: LEFT JOIN mes_anterior ON ... THEN CASE WHEN lista_clientes IS NULL THEN 'nuevo' (esto da 'recurrente' a TODOS cuando el mes anterior existe)\n" .
            "  → BIEN: EXISTS (SELECT 1 FROM clientes_por_mes prev WHERE prev.client_id = cpm.client_id AND prev.mes_num = cpm.mes_num - 1)\n" .
            "  → Aplica a: retención de clientes, clientes nuevos vs recurrentes, cualquier comparación de sets entre períodos.\n" .
            "- ⚠ PERCENTILE_CONT / PERCENTILE_DISC no existen en MariaDB. Para percentiles usar NTILE(N) OVER (ORDER BY col ASC/DESC):\n" .
            "  → Top 20% (percentil 80): NTILE(5) OVER (ORDER BY valor ASC) = 5\n" .
            "  → Mediana (percentil 50): NTILE(2) OVER (ORDER BY valor ASC) = 2\n" .
            "- ⚠ NUNCA uses FULL OUTER JOIN — MariaDB/MySQL NO lo soporta. Para combinar dos conjuntos donde ambos pueden tener filas sin match:\n" .
            "  → Patrón correcto: CTE adicional con UNION de ambos lados + dos LEFT JOINs:\n" .
            "     WITH cte_a AS (...), cte_b AS (...), todos AS (SELECT key FROM cte_a UNION SELECT key FROM cte_b)\n" .
            "     SELECT ... FROM todos LEFT JOIN cte_a ON ... LEFT JOIN cte_b ON ...\n" .
            "  → Aplica siempre que necesites comparar dos períodos (año actual vs año anterior, mes vs mes) por ejecutiva, cliente, producto, etc.\n" .
            "- ⚠ CTEs que deben devolver UNA FILA POR CLAVE: si usas un CTE para obtener un valor único por entidad (ej: TRM por OC, última fecha por cliente) y luego haces JOIN, el CTE DEBE tener GROUP BY en la clave — sin GROUP BY devuelve todas las filas y el JOIN multiplica los resultados.\n" .
            "  → MAL: SELECT order_id, trm FROM partials WHERE ... ORDER BY dispatch_date DESC  ← devuelve N filas por order_id\n" .
            "  → BIEN: SELECT order_id, MAX(NULLIF(trm+0,0)) AS trm FROM partials WHERE ... GROUP BY order_id\n" .
            "  → Aplica a: TRM por OC, última fecha por cliente/OC, cualquier 'último valor' por entidad.\n" .
            "- ⚠ WITH ROLLUP — REGLA CRÍTICA: GROUP BY debe tener exactamente UNA columna cuando usas WITH ROLLUP para obtener solo el subtotal general. Cada columna extra en el GROUP BY genera N niveles adicionales de subtotales intermedios → filas duplicadas.\n" .
            "  → MAL: GROUP BY pp.executive, ea.nombre_real, cv.clientes_con_vencido, tc.total_clientes WITH ROLLUP  ← produce 17 filas para 4 ejecutivas\n" .
            "  → BIEN: GROUP BY pp.executive WITH ROLLUP  ← produce exactamente 5 filas (4 ejecutivas + 1 TOTAL)\n" .
            "  → Las otras columnas (nombre_real, clientes_con_vencido, etc.) van con MAX() o ANY_VALUE() en el SELECT\n" .
            "  → Fila del TOTAL: COALESCE(MAX(ea.nombre_real), 'TOTAL') AS ejecutiva\n\n" .
            "SEGUIMIENTO DHL — GUÍAS DE DESPACHO:\n" .
            "Cuando el usuario pregunte por una GUÍA (número 10 dígitos) O por una ORDEN DE COMPRA (patrón YYYY-NNNN, ej: 2327-2699) SIEMPRE debes hacer DOS cosas:\n" .
            "1. Si hay sección [DATOS DE SEGUIMIENTO DHL — consultados en tiempo real]: interpreta en el html (estado, ubicación, último movimiento). Si dice que no tiene guías: indícalo. Si hay error: explícalo brevemente.\n" .
            "2. SIEMPRE genera el SQL de timeline UNION ALL. Adapta la subquery según el caso:\n" .
            "   - Por GUÍA: usa tracking_number='{NUMERO}' como se muestra abajo.\n" .
            "   - Por OC (formato variable: 2327-2699, 2320-2317-18159, 2301-10-2026, etc.): reemplaza la subquery de búsqueda por:\n" .
            "     (SELECT id FROM purchase_orders WHERE order_consecutive='{NUMERO_OC}' LIMIT 1)\n" .
            "     Y en la fase 3: WHERE par_r.order_id=(SELECT id FROM purchase_orders WHERE order_consecutive='{NUMERO_OC}' LIMIT 1) AND par_r.type='real' AND par_r.deleted_at IS NULL\n" .
            "   Columnas fijas en las tres partes del UNION: fase, fecha, numero_oc, estado_oc, cliente, nit, ejecutiva, factura, guia, transportador, kilos\n" .
            "   NOTA: el campo del nombre del cliente en la tabla clients es c.client_name (NO c.name).\n" .
            "   SQL obligatorio cuando hay número de guía (UNION ALL sin CTE — MariaDB no materializa CTEs referenciados múltiples veces):\n\n" .
            "   SQL obligatorio cuando hay número de guía — incluye fase_orden para ordenar creación→estimado→real independientemente de fechas:\n\n" .
            "   -- El estado_oc se infiere por fase (po.status es siempre el actual, no histórico):\n" .
            "   -- creación → siempre 'pending' | estimado → siempre 'processing' | real → po.status actual\n" .
            "   SELECT 'creación' AS fase, 1 AS fase_orden, po.order_creation_date AS fecha,\n" .
            "     po.order_consecutive AS numero_oc, 'pending' AS estado_oc,\n" .
            "     c.client_name AS cliente, c.nit,\n" .
            "     REPLACE(SUBSTRING_INDEX(c.executive,'@',1),'.',' ') AS ejecutiva,\n" .
            "     NULL AS factura, NULL AS guia, NULL AS transportador, NULL AS kilos\n" .
            "   FROM purchase_orders po\n" .
            "   JOIN clients c ON c.id = po.client_id\n" .
            "   WHERE po.id = (SELECT order_id FROM partials WHERE tracking_number='{NUMERO_GUIA}' AND type='real' AND deleted_at IS NULL LIMIT 1)\n" .
            "   UNION ALL\n" .
            "   SELECT 'estimado (Marlon)', 2, par_t.dispatch_date,\n" .
            "     po.order_consecutive, 'processing', c.client_name, c.nit,\n" .
            "     REPLACE(SUBSTRING_INDEX(c.executive,'@',1),'.',' '),\n" .
            "     NULL, NULL, NULL, par_t.quantity\n" .
            "   FROM partials par_t\n" .
            "   JOIN purchase_orders po ON po.id = par_t.order_id\n" .
            "   JOIN clients c ON c.id = po.client_id\n" .
            "   WHERE par_t.order_id = (SELECT order_id FROM partials WHERE tracking_number='{NUMERO_GUIA}' AND type='real' AND deleted_at IS NULL LIMIT 1)\n" .
            "     AND par_t.type = 'temporal' AND par_t.deleted_at IS NULL\n" .
            "   UNION ALL\n" .
            "   SELECT 'despacho real (Alexa)', 3, par_r.dispatch_date,\n" .
            "     po.order_consecutive, po.status, c.client_name, c.nit,\n" .
            "     REPLACE(SUBSTRING_INDEX(c.executive,'@',1),'.',' '),\n" .
            "     par_r.invoice_number, par_r.tracking_number, par_r.transporter, par_r.quantity\n" .
            "   FROM partials par_r\n" .
            "   JOIN purchase_orders po ON po.id = par_r.order_id\n" .
            "   JOIN clients c ON c.id = po.client_id\n" .
            "   WHERE par_r.tracking_number = '{NUMERO_GUIA}'\n" .
            "     AND par_r.type = 'real' AND par_r.deleted_at IS NULL\n" .
            "   ORDER BY fase_orden DESC, fecha DESC\n\n" .
            "- En \"showing\": fase, fecha, número de OC, cliente, estado, factura, guía, kilos.\n" .
            "- En \"available\": transportador, NIT, ejecutiva.\n" .
            "- El html debe incluir TANTO el resumen DHL (o el error) COMO una nota indicando que abajo se muestra el proceso completo de la OC en Finearom.";
    }

    /**
     * Retorna el contexto dinámico del período activo (TRM, fechas).
     * Se envía como primer mensaje 'user' después del system prompt.
     */
    private function buildPeriodContext(string $startDate, string $endDate, string $today, string $trmHoyStr): string
    {
        return "Período activo de esta sesión: {$startDate} al {$endDate}.\n" .
               "TRM de hoy ({$today}): {$trmHoyStr} COP/USD — usar SOLO para convertir valores de cartera a USD si se piden. Las órdenes tienen su propia TRM individual.";
    }

    /**
     * Envía una conversación a DeepSeek y retorna la respuesta.
     * El primer mensaje (prompt inicial con schema SQL) se beneficia del prefix caching
     * automático de DeepSeek cuando el prefijo es idéntico entre llamadas.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return array{success: bool, content: string}
     */
    private function callDeepSeekApi(array $messages): array
    {
        $key = config('custom.deepseek_api_key');

        $resp = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type'  => 'application/json',
            ])
            ->timeout(120)
            ->post('https://api.deepseek.com/chat/completions', [
                'model'       => 'deepseek-chat',
                'messages'    => $messages,
                'temperature' => 0.1,
                'max_tokens'  => 3000,
                'stream'      => false,
            ]);

        if (!$resp->successful()) {
            Log::error('[Chat][DeepSeek] Error API: ' . $resp->body());
            return ['success' => false, 'content' => ''];
        }

        // Loguear stats de cache para verificar que el prefix caching está funcionando.
        // DeepSeek cachea automáticamente el prefijo cuando el primer mensaje no cambia.
        $usage = $resp->json('usage', []);
        Log::info('[Chat][DeepSeek] Tokens — prompt: ' . ($usage['prompt_tokens'] ?? '?')
            . ', completion: ' . ($usage['completion_tokens'] ?? '?')
            . ', cache_hit: ' . ($usage['prompt_cache_hit_tokens'] ?? 0)
            . ', cache_miss: ' . ($usage['prompt_cache_miss_tokens'] ?? 0));

        return [
            'success' => true,
            'content' => $this->parseStructuredResponse(trim($resp->json('choices.0.message.content', ''))),
        ];
    }

    /**
     * Detecta guía DHL (10 dígitos) o número de OC (YYYY-NNNN) en el mensaje
     * y consulta el tracking en DHL. Retorna texto para inyectar al contexto, o null.
     */
    private function fetchDhlContextForMessage(string $message): ?string
    {
        $dhl = app(DhlService::class);

        // 1. Número de guía directo (10 dígitos)
        if (preg_match('/\b(\d{10})\b/', $message, $matches)) {
            $result = $dhl->trackShipment($matches[1]);
            if (!$result['success']) {
                return "No se pudo consultar la guía {$matches[1]} en DHL: {$result['error']}";
            }
            return $dhl->formatForChat($result['data']);
        }

        // 2. Número de OC — formato: 4 dígitos + guión + cualquier combinación de dígitos y guiones
        // Ejemplos: 2327-2699, 2320-2317-18159, 2301-10-2026, 2324-4500304578
        if (preg_match('/\b(\d{4}-[\d-]+)\b/', $message, $matches)) {
            $orderConsecutive = $matches[1];

            $trackingNumbers = DB::table('partials')
                ->join('purchase_orders', 'purchase_orders.id', '=', 'partials.order_id')
                ->where('purchase_orders.order_consecutive', $orderConsecutive)
                ->where('partials.type', 'real')
                ->whereNull('partials.deleted_at')
                ->whereNotNull('partials.tracking_number')
                ->where('partials.tracking_number', '!=', '')
                ->pluck('partials.tracking_number')
                ->unique()
                ->values();

            if ($trackingNumbers->isEmpty()) {
                return "La orden {$orderConsecutive} no tiene guías de envío registradas aún.";
            }

            $parts = ["Guías DHL encontradas para la orden {$orderConsecutive}:"];
            foreach ($trackingNumbers as $tracking) {
                $result = $dhl->trackShipment($tracking);
                $parts[] = "\n--- Guía {$tracking} ---";
                $parts[] = $result['success']
                    ? $dhl->formatForChat($result['data'])
                    : "No se pudo consultar en DHL: {$result['error']}";
            }

            return implode("\n", $parts);
        }

        return null;
    }

    /**
     * Normaliza la respuesta de la IA para garantizar que los bloques SQL
     * estén siempre en el formato que espera el frontend (<pre><code class="language-sql">).
     *
     * Convierte:
     *   ```sql\nSELECT...\n```  →  <pre><code class="language-sql">SELECT...</code></pre>
     *   ```\nSELECT...\n```     →  igual (bloques genéricos con contenido SQL)
     *   SQL plano sin wrapper   →  wrapeado si empieza con SELECT/WITH/--
     */
    /**
     * Parsea la respuesta estructurada JSON de la IA y la convierte al HTML
     * que espera el frontend (con <pre><code class="language-sql"> para las queries).
     *
     * Formato esperado de la IA:
     *   {"html":"<p>...</p>","sql":"SELECT ...","showing":"campo1","available":"campo2"}
     *
     * Fallbacks en orden si el JSON no es válido:
     *   1. Quitar wrapper ```json...``` y reintentar
     *   2. Detectar bloques ```sql...``` en la respuesta
     *   3. Detectar SQL plano (empieza con SELECT/WITH/--)
     *   4. Devolver el contenido tal cual
     */
    private function parseStructuredResponse(string $content): string
    {
        $json = trim($content);

        // Quitar wrapper ```json...``` si la IA lo puso igual
        if (preg_match('/^```(?:json)?\s*\r?\n([\s\S]*?)\r?\n?```$/i', $json, $m)) {
            $json = trim($m[1]);
        }

        $data = json_decode($json, true);

        if (is_array($data)) {
            $html = trim($data['html'] ?? '');
            $sql  = isset($data['sql']) && $data['sql'] !== null ? trim($data['sql']) : null;

            $result = $html;

            if ($sql) {
                $result .= '<pre><code class="language-sql">' . $sql . '</code></pre>';
            }

            if (!empty($data['showing'])) {
                $result .= '<p><small>Mostrando: <strong>' . htmlspecialchars($data['showing'], ENT_QUOTES) . '</strong>.</small></p>';
            }
            if (!empty($data['available'])) {
                $result .= '<p><small>Puedes pedirme que también muestre: ' . htmlspecialchars($data['available'], ENT_QUOTES) . '.</small></p>';
            }

            return $result;
        }

        // Fallback 1: bloques markdown ```sql...```
        $content = preg_replace_callback(
            '/```(?:sql)?\s*\r?\n([\s\S]*?)\r?\n?```/i',
            function ($m) {
                $sql = trim($m[1]);
                return $sql ? '<pre><code class="language-sql">' . $sql . '</code></pre>' : '';
            },
            $content
        );

        // Fallback 2: respuesta que ES directamente SQL plano
        if (!str_contains($content, '<') && preg_match('/^\s*(SELECT|WITH\s|--)/i', $content)) {
            $content = '<pre><code class="language-sql">' . trim($content) . '</code></pre>';
        }

        return $content;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Límites diarios por modelo
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verifica el límite diario del modelo y, si no se ha superado, incrementa el contador.
     * Retorna JsonResponse 429 si se alcanzó el límite; null si se puede continuar.
     *
     * Modelos con límite (10 req/usuario/día):
     *   - claude-sonnet-4-5  (nivel Avanzado)
     *   - o3-mini            (nivel Premium)
     * Modelos sin límite: cualquier otro (incluido gpt-4.1).
     */
    private function checkAndIncrementModelLimit(string $model): ?JsonResponse
    {
        $limitedModels = ['claude-sonnet-4-5'];

        if (!in_array($model, $limitedModels, true)) {
            return null;
        }

        $bogota  = Carbon::now('America/Bogota');
        $key     = 'chat_limit:' . auth()->id() . ':' . $model . ':' . $bogota->toDateString();
        $ttl     = $bogota->secondsUntilEndOfDay();
        $current = (int) Cache::get($key, 0);

        if ($current >= 10) {
            Log::info("[ChatLimit] Límite alcanzado user=" . auth()->id() . " model={$model} count={$current}");
            return response()->json([
                'success'       => false,
                'message'       => 'Límite diario de 10 peticiones alcanzado para este modelo. Se reinicia a medianoche.',
                'limit_exceeded' => true,
            ], 429);
        }

        if ($current === 0) {
            Cache::put($key, 1, $ttl);
        } else {
            Cache::increment($key);
        }

        return null;
    }

    /**
     * Retorna el estado actual de uso de límites diarios por modelo para el usuario autenticado.
     */
    public function chatModelLimits(Request $request): JsonResponse
    {
        $bogota      = Carbon::now('America/Bogota');
        $today       = $bogota->toDateString();
        $userId      = auth()->id();
        $limitedModels = ['claude-sonnet-4-5'];
        $limit       = 10;

        $result = [];

        foreach ($limitedModels as $model) {
            $key  = "chat_limit:{$userId}:{$model}:{$today}";
            $used = (int) Cache::get($key, 0);
            $result[$model] = [
                'used'      => $used,
                'limit'     => $limit,
                'remaining' => max(0, $limit - $used),
            ];
        }

        // Modelos sin límite
        $result['gpt-4.1'] = [
            'used'      => null,
            'limit'     => null,
            'remaining' => null,
        ];

        return response()->json(['success' => true, 'data' => $result]);
    }

    /**
     * Ejecuta una query SELECT enviada por el frontend (generada por la IA).
     * Solo permite SELECT — bloquea cualquier otra operación DML/DDL.
     */
    public function runQuery(Request $request): JsonResponse
    {
        $request->validate(['sql' => 'required|string|max:5000']);

        $sql = trim($request->input('sql'));

        // Solo SELECT y CTEs (WITH ... SELECT) permitidos.
        // Se permiten comentarios SQL (-- ...) antes de la query.
        if (!preg_match('/^\s*(--[^\n]*\n\s*)*(SELECT|WITH)\s/is', $sql)) {
            return response()->json(['success' => false, 'message' => 'Solo se permiten consultas SELECT'], 422);
        }

        // Bloquear patrones peligrosos
        $forbidden = ['DROP ', 'DELETE ', 'UPDATE ', 'INSERT ', 'ALTER ', 'CREATE ', 'TRUNCATE ', 'EXEC(', 'EXECUTE(', 'LOAD_FILE', 'INTO OUTFILE', 'INTO DUMPFILE'];
        foreach ($forbidden as $pattern) {
            if (stripos($sql, $pattern) !== false) {
                return response()->json(['success' => false, 'message' => 'Query no permitida'], 422);
            }
        }

        try {
            $results = DB::select($sql);

            if (empty($results)) {
                return response()->json(['success' => true, 'columns' => [], 'rows' => [], 'count' => 0]);
            }

            $columns = array_keys((array) $results[0]);
            $rows    = array_map(fn($row) => array_values((array) $row), $results);

            return response()->json([
                'success' => true,
                'columns' => $columns,
                'rows'    => $rows,
                'count'   => count($rows),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 422);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Generar y guardar reporte en disco
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Genera el reporte mensual y lo guarda en storage/app/monthly_report.md
     * Siempre sobreescribe el archivo anterior.
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();

        try {
            $report = [
                'period'      => ['start_date' => $startDate, 'end_date' => $endDate],
                'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
                'stats'       => $this->buildStats($startDate, $endDate),
            ];

            $md = $this->buildReportMarkdown($report);
            Storage::disk('local')->put('monthly_report.md', $md);

            Log::info("[MonthlyReport] Reporte generado y guardado ({$startDate} → {$endDate})");

            return response()->json([
                'success'       => true,
                'message'       => 'Reporte generado correctamente',
                'generated_at'  => now()->toIso8601String(),
                'period'        => $report['period'],
                'ordenes_count' => count($report['ordenes_mes']),
            ]);
        } catch (\Throwable $e) {
            Log::error('MonthlyReport generate error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error generando reporte'], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Análisis IA
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Genera un análisis IA del reporte mensual:
     * 1. Envía el JSON completo al servidor AI y pide 8 preguntas de análisis.
     * 2. Responde cada pregunta en el mismo hilo (la IA recuerda el contexto).
     * 3. Devuelve todas las respuestas agrupadas.
     */
    public function analyze(Request $request): JsonResponse
    {
        set_time_limit(0); // puede tomar varios minutos (9 llamadas al servidor AI)

        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date'   => 'nullable|date_format:Y-m-d',
        ]);

        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();

        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        Log::info("[AI-Analyze] Iniciando análisis período {$startDate} → {$endDate}");

        try {
            // 1. Construir reporte
            Log::info('[AI-Analyze] Construyendo reporte (ordenes + stats)...');
            $reportData = [
                'periodo' => ['start_date' => $startDate, 'end_date' => $endDate],
                'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
                'stats'   => $this->buildStats($startDate, $endDate),
            ];
            $ordersCount = count($reportData['ordenes_mes']);
            Log::info("[AI-Analyze] Reporte construido: {$ordersCount} órdenes. Enviando al servidor AI...");

            // 2. Primer prompt: enviar reporte y obtener 8 preguntas de análisis
            $resp1 = Http::withHeaders(['X-Api-Key' => $aiKey])
                ->timeout(310)
                ->post("{$aiUrl}/v1/chat/completions", [
                    'model'        => 'gpt-4.1',
                    'messages'     => [['role' => 'user', 'content' => $this->buildInitialPrompt($reportData, $startDate, $endDate)]],
                    'extract_json' => true,
                ]);

            Log::info("[AI-Analyze] Prompt inicial → HTTP {$resp1->status()}");

            if (!$resp1->successful()) {
                Log::error('[AI-Analyze] Error en prompt inicial: ' . $resp1->body());
                throw new \RuntimeException("AI server error {$resp1->status()}: " . $resp1->body());
            }

            $threadId = $resp1->json('thread_id');
            $content1 = $resp1->json('choices.0.message.content', '');
            Log::info("[AI-Analyze] thread_id={$threadId} | Respuesta (primeros 300): " . substr($content1, 0, 300));

            $questions = $this->parseQuestions($content1);
            Log::info('[AI-Analyze] Preguntas parseadas: ' . count($questions));

            if (empty($questions)) {
                Log::error('[AI-Analyze] No se pudieron parsear preguntas. Contenido completo: ' . $content1);
                throw new \RuntimeException('El servidor AI no generó preguntas válidas. Respuesta: ' . substr($content1, 0, 300));
            }

            // 3. Responder cada pregunta en el mismo hilo
            $analisis = [];
            foreach ($questions as $i => $question) {
                $num = $i + 1;
                Log::info("[AI-Analyze] Pregunta {$num}/".count($questions).": " . substr($question, 0, 100));

                $respN = Http::withHeaders(['X-Api-Key' => $aiKey])
                    ->timeout(310)
                    ->post("{$aiUrl}/v1/chat/completions", [
                        'model'     => 'gpt-4.1',
                        'messages'  => [['role' => 'user', 'content' => $this->buildQuestionPrompt($question)]],
                        'thread_id' => $threadId,
                    ]);

                Log::info("[AI-Analyze] Respuesta {$num} → HTTP {$respN->status()}");

                if (!$respN->successful()) {
                    Log::warning("[AI-Analyze] Error en pregunta {$num} (thread={$threadId}): " . $respN->body());
                    $answer = 'No se pudo obtener respuesta para esta pregunta.';
                } else {
                    $answer = trim($respN->json('choices.0.message.content', ''));
                    Log::info("[AI-Analyze] Respuesta {$num} OK (" . strlen($answer) . " chars)");
                }

                $analisis[] = [
                    'pregunta'  => $question,
                    'respuesta' => $answer,
                ];
            }

            Log::info('[AI-Analyze] Análisis completado. ' . count($analisis) . ' respuestas generadas.');

            return response()->json([
                'success'     => true,
                'periodo'     => ['start_date' => $startDate, 'end_date' => $endDate],
                'thread_id'   => $threadId,
                'analisis'    => $analisis,
                'generado_en' => now()->toIso8601String(),
            ]);

        } catch (\Throwable $e) {
            Log::error('[AI-Analyze] ERROR: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Análisis IA — Streaming (Server-Sent Events)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Igual que analyze() pero devuelve los resultados en tiempo real via SSE.
     * El cliente recibe cada respuesta a medida que la IA la procesa.
     *
     * Formato de eventos:
     *   data: {"type":"start","total":8}
     *   data: {"type":"report_ready","ordenes_count":X}
     *   data: {"type":"questions_ready","preguntas":[...]}
     *   data: {"type":"answer","index":1,"total":8,"pregunta":"...","respuesta":"..."}
     *   data: {"type":"done","thread_id":"...","generado_en":"..."}
     *   data: {"type":"error","message":"..."}
     */
    public function stream(Request $request): StreamedResponse
    {
        $startDate = $request->get('start_date') ?? Carbon::now()->startOfMonth()->toDateString();
        $endDate   = $request->get('end_date')   ?? Carbon::now()->endOfMonth()->toDateString();
        $aiUrl     = config('custom.ai_server_url');
        $aiKey     = config('custom.ai_server_key');

        return new StreamedResponse(function () use ($startDate, $endDate, $aiUrl, $aiKey) {
            set_time_limit(0);

            // Helper: enviar un evento SSE
            $send = function (array $data) {
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
            };

            try {
                $send(['type' => 'start', 'total' => 8, 'periodo' => ['start_date' => $startDate, 'end_date' => $endDate]]);

                // 1. Construir reporte
                $reportData = [
                    'periodo' => ['start_date' => $startDate, 'end_date' => $endDate],
                    'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
                    'stats'   => $this->buildStats($startDate, $endDate),
                ];
                $send(['type' => 'report_ready', 'ordenes_count' => count($reportData['ordenes_mes'])]);

                // 2. Primer prompt: generar preguntas
                $send(['type' => 'status', 'message' => 'Analizando reporte con IA...']);
                $resp1 = Http::withHeaders(['X-Api-Key' => $aiKey])
                    ->timeout(310)
                    ->post("{$aiUrl}/v1/chat/completions", [
                        'model'        => 'gpt-4.1',
                        'messages'     => [['role' => 'user', 'content' => $this->buildInitialPrompt($reportData, $startDate, $endDate)]],
                        'extract_json' => true,
                    ]);

                if (!$resp1->successful()) {
                    $send(['type' => 'error', 'message' => "AI server error {$resp1->status()}"]);
                    return;
                }

                $threadId  = $resp1->json('thread_id');
                $content1  = $resp1->json('choices.0.message.content', '');
                $questions = $this->parseQuestions($content1);

                if (empty($questions)) {
                    $send(['type' => 'error', 'message' => 'No se generaron preguntas válidas. Respuesta: ' . substr($content1, 0, 200)]);
                    return;
                }

                $send(['type' => 'questions_ready', 'preguntas' => $questions]);

                // 3. Responder cada pregunta en el mismo hilo
                $analisis = [];
                foreach ($questions as $i => $question) {
                    $index = $i + 1;
                    $send(['type' => 'status', 'message' => "Respondiendo pregunta {$index} de " . count($questions) . '...']);

                    $respN = Http::withHeaders(['X-Api-Key' => $aiKey])
                        ->timeout(310)
                        ->post("{$aiUrl}/v1/chat/completions", [
                            'model'     => 'gpt-4.1',
                            'messages'  => [['role' => 'user', 'content' => $this->buildQuestionPrompt($question)]],
                            'thread_id' => $threadId,
                        ]);

                    $answer = $respN->successful()
                        ? trim($respN->json('choices.0.message.content', ''))
                        : 'No se pudo obtener respuesta.';

                    $item = ['pregunta' => $question, 'respuesta' => $answer];
                    $analisis[] = $item;

                    $send(['type' => 'answer', 'index' => $index, 'total' => count($questions)] + $item);
                }

                $send(['type' => 'done', 'thread_id' => $threadId, 'analisis' => $analisis, 'generado_en' => now()->toIso8601String()]);

            } catch (\Throwable $e) {
                Log::error('[AI-Stream] ERROR: ' . $e->getMessage());
                $send(['type' => 'error', 'message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache',
            'X-Accel-Buffering' => 'no', // desactiva buffering en nginx
            'Connection'        => 'keep-alive',
        ]);
    }

    private function buildInitialPrompt(array $reportData, string $startDate, string $endDate): string
    {
        $json = json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Eres un analista comercial experto. Acabas de recibir el reporte mensual de Finearom
para el período {$startDate} al {$endDate}.

El reporte tiene dos secciones:
- "ordenes": órdenes despachadas con sus productos, clientes, valores USD/COP y TRM
- "stats": estadísticas agregadas (despachos, planeado, recaudos, cartera, TRM, stale_orders, top_productos)

Nota: los productos con "es_muestra: true" tienen precio \$0 (muestras comerciales).
No cuentan en los totales financieros pero sí representan actividad comercial.

REPORTE:
{$json}

Con base en estos datos, genera exactamente 8 preguntas de análisis concretas que investigaremos
una por una. Las preguntas deben estar respaldadas por los datos y cubrir estos ángulos:
1. Productos con mayor volumen despachado
2. Clientes con mayor valor generado
3. Cumplimiento: despachado real vs planeado
4. Alertas: órdenes estancadas o riesgos detectados
5. Recaudos y cobertura de cartera
6. Impacto del TRM en los totales COP
7. Muestras vs ventas comerciales reales
8. Una oportunidad o recomendación concreta para el siguiente mes

Responde ÚNICAMENTE con este JSON, sin texto adicional:
{"preguntas": ["...", "...", "...", "...", "...", "...", "...", "..."]}
PROMPT;
    }

    private function buildQuestionPrompt(string $question): string
    {
        return <<<PROMPT
Responde la siguiente pregunta usando los datos del reporte mensual que ya analizaste.
Sé específico: menciona nombres de productos, clientes, valores exactos y porcentajes cuando corresponda.
Máximo 150 palabras, respuesta directa en español.

Pregunta: {$question}
PROMPT;
    }

    private function parseQuestions(string $content): array
    {
        // Intentar parseo directo
        $decoded = json_decode($content, true);
        if (isset($decoded['preguntas']) && is_array($decoded['preguntas'])) {
            return array_values($decoded['preguntas']);
        }

        // Extraer JSON del texto si viene envuelto en markdown u otro texto
        if (preg_match('/\{[\s\S]*"preguntas"[\s\S]*\}/u', $content, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (isset($decoded['preguntas']) && is_array($decoded['preguntas'])) {
                return array_values($decoded['preguntas']);
            }
        }

        return [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Órdenes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construye el array de órdenes CREADAS en el período (todos los estados).
     * Las órdenes despachadas tienen status = completed | parcial_status.
     * Las no despachadas tienen status = pending | processing.
     */
    private function buildOrdenes(string $startDate, string $endDate): array
    {
        $orders = PurchaseOrder::with(['client', 'branchOffice', 'project'])
            ->whereBetween('order_creation_date', [$startDate, $endDate])
            ->orderBy('order_creation_date')
            ->get();

        return $orders->map(function (PurchaseOrder $order) {
            $orderTrm = (float) ($order->trm ?? 4000);

            // Partials reales — misma query que GoogleSheetsService
            $partials = DB::table('partials')
                ->join('purchase_order_product as pop', 'partials.product_order_id', '=', 'pop.id')
                ->join('products as p', 'pop.product_id', '=', 'p.id')
                ->where('partials.type', 'real')
                ->where('pop.purchase_order_id', $order->id)
                ->whereNull('partials.deleted_at')
                ->select(
                    'partials.quantity',
                    'partials.dispatch_date',
                    'partials.invoice_number',
                    'partials.tracking_number',
                    'partials.transporter',
                    'partials.trm as partial_trm',
                    'pop.muestra',
                    'pop.new_win',
                    'pop.price as pivot_price',
                    'p.price as product_price',
                    'p.product_name',
                )
                ->get();

            $totalUsd    = 0.0;
            $totalCop    = 0.0;
            $transporter = '';
            $invoiceNum  = $order->invoice_number ?? '';
            $trackingNum = $order->tracking_number ?? '';
            $productos   = [];

            foreach ($partials as $partial) {
                $isSample = (int) $partial->muestra === 1;

                $effectivePrice = $isSample ? 0.0
                    : (float) (($partial->pivot_price > 0) ? $partial->pivot_price : ($partial->product_price ?? 0));

                $trm = ((float)($partial->partial_trm ?? 0) > 0)
                    ? (float) $partial->partial_trm
                    : ($orderTrm > 0 ? $orderTrm : 4000.0);

                $qty         = (int) $partial->quantity;
                $subtotalUsd = $effectivePrice * $qty;
                $subtotalCop = $subtotalUsd * $trm;

                $totalUsd += $subtotalUsd;
                $totalCop += $subtotalCop;

                if (!$transporter && $partial->transporter) {
                    $transporter = $partial->transporter;
                }
                if (!$invoiceNum && $partial->invoice_number) {
                    $invoiceNum = $partial->invoice_number;
                }
                if (!$trackingNum && $partial->tracking_number) {
                    $trackingNum = $partial->tracking_number;
                }

                $productos[] = [
                    'producto'      => $partial->product_name ?? '',
                    'cantidad'      => $qty,
                    'precio_usd'    => round($effectivePrice, 2),
                    'subtotal_usd'  => round($subtotalUsd, 2),
                    'subtotal_cop'  => round($subtotalCop, 0),
                    'trm'           => round($trm, 2),
                    'es_muestra'    => $isSample,
                    'new_win'       => (int) ($partial->new_win ?? 0) === 1,
                    'fecha_despacho'=> $partial->dispatch_date,
                    'factura'       => $partial->invoice_number ?? '',
                    'guia'          => $partial->tracking_number ?? '',
                    'transportadora'=> $partial->transporter ?? '',
                ];
            }

            // Fallback si no hay partials reales
            if ($partials->isEmpty()) {
                $order->load('products');
                foreach ($order->products as $product) {
                    $isSample   = (bool) ($product->pivot->muestra ?? false);
                    $pivotPrice = (float) ($product->pivot->price ?? 0);
                    $price      = $isSample ? 0.0 : ($pivotPrice > 0 ? $pivotPrice : (float) ($product->price ?? 0));
                    $qty        = (int) ($product->pivot->quantity ?? 0);
                    $subUsd     = $price * $qty;
                    $trm        = $orderTrm > 0 ? $orderTrm : 4000.0;
                    $subCop     = $subUsd * $trm;

                    $totalUsd += $subUsd;
                    $totalCop += $subCop;

                    $productos[] = [
                        'producto'      => $product->product_name ?? '',
                        'cantidad'      => $qty,
                        'precio_usd'    => round($price, 2),
                        'subtotal_usd'  => round($subUsd, 2),
                        'subtotal_cop'  => round($subCop, 0),
                        'trm'           => round($trm, 2),
                        'es_muestra'    => $isSample,
                        'new_win'       => (bool) ($product->pivot->new_win ?? false),
                        'fecha_despacho'=> null,
                        'factura'       => '',
                        'guia'          => '',
                        'transportadora'=> '',
                    ];
                }
            }

            $trm = $orderTrm > 0 ? $orderTrm : 4000.0;

            return [
                'fecha_despacho'   => $order->dispatch_date?->toDateString(),
                'consecutivo'      => $order->order_consecutive ?? '',
                'estado'           => $order->status,
                'cliente'          => $order->client?->client_name ?? '',
                'nit'              => $order->client?->nit ?? '',
                'sucursal'         => $order->branchOffice?->branch_name ?? '',
                'ciudad_entrega'   => $order->delivery_city ?? '',
                'total_usd'        => round($totalUsd, 2),
                'total_cop'        => round($totalCop, 0),
                'trm'              => round($trm, 2),
                'factura'          => $invoiceNum,
                'guia'             => $trackingNum,
                'transportadora'   => $transporter,
                'es_muestra'       => (bool) $order->is_muestra,
                'new_win'          => (bool) $order->is_new_win,
                'proyecto'         => $order->project?->name ?? '',
                'ejecutivo'        => $this->normalizeExecutiveName($order->client?->executive ?? ''),
                'direccion_entrega'=> $order->delivery_address ?? '',
                'productos'        => $productos,
            ];
        })->values()->all();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Stats — misma lógica que DashboardController::getStats
    // ─────────────────────────────────────────────────────────────────────────

    private function buildStats(string $startDate, string $endDate): array
    {
        // Pre-computed daily snapshots (solo para métricas secundarias)
        $dailyStats = DB::table('order_statistics')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();

        $daysCount = $dailyStats->count();

        // Despachos reales del período — misma lógica que DashboardController::calculateExecutiveStats()
        $dispatchRow = DB::table('partials as pt')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->join('purchase_orders as po', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'pt.dispatch_date', '=', 'td.date')
            ->where('pt.type', 'real')
            ->whereNotNull('pt.dispatch_date')
            ->whereBetween('pt.dispatch_date', [$startDate, $endDate])
            ->where('pop.muestra', '=', 0)
            ->selectRaw("
                COUNT(DISTINCT po.id) as orders_count,
                SUM((CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity) as value_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity *
                    (CASE
                        WHEN pt.trm >= 3400 THEN pt.trm
                        WHEN td.value IS NOT NULL THEN td.value
                        ELSE 4000
                    END)
                ) as value_cop
            ")
            ->first();

        $totalUsd    = (float) ($dispatchRow->value_usd   ?? 0);
        $totalCop    = (float) ($dispatchRow->value_cop   ?? 0);
        $totalOrders = (int)   ($dispatchRow->orders_count ?? 0);
        $totalProducts = $dailyStats->sum('commercial_products_dispatched');

        // Total kilos dispatched (real partials in period, excluding samples)
        $totalKilosDispatched = (float) DB::table('partials as pt')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->where('pt.type', 'real')
            ->whereNotNull('pt.dispatch_date')
            ->whereBetween('pt.dispatch_date', [$startDate, $endDate])
            ->where('pop.muestra', 0)
            ->sum('pt.quantity');

        // Real-time planned stats
        $plannedUsd = 0;
        $plannedCop = 0;
        $plannedOrdersCount = 0;

        $orderProducts = DB::table('purchase_order_product')
            ->join('purchase_orders', 'purchase_order_product.purchase_order_id', '=', 'purchase_orders.id')
            ->join('products', 'purchase_order_product.product_id', '=', 'products.id')
            ->leftJoin('partials', function ($join) {
                $join->on('partials.product_order_id', '=', 'purchase_order_product.id')
                    ->whereIn('partials.type', ['real', 'temporal']);
            })
            ->select(
                'purchase_orders.id as order_id',
                'purchase_orders.trm as order_trm',
                'purchase_order_product.quantity',
                'purchase_order_product.price as order_product_price',
                'purchase_order_product.muestra',
                'products.price as product_price',
                'partials.dispatch_date as partial_dispatch_date',
                'partials.type as partial_type',
                'partials.trm as partial_trm',
                'purchase_orders.order_creation_date',
            )
            ->get();

        $trmData = DB::table('trm_daily')
            ->whereBetween('date', [$startDate, $endDate])
            ->pluck('value', 'date')
            ->toArray();

        $ordersSet = [];
        foreach ($orderProducts->groupBy('order_id') as $orderId => $items) {
            $first      = $items->first();
            $isSample   = (int) $first->muestra === 1;
            $qty        = (int) $first->quantity;

            // Determine planned dispatch date
            $realPartial = $items->where('partial_type', 'real')->whereNotNull('partial_dispatch_date')->first();
            $tempPartial = $items->where('partial_type', 'temporal')->whereNotNull('partial_dispatch_date')->first();

            if ($realPartial) {
                $plannedDate = $realPartial->partial_dispatch_date;
                $partialTrm  = $realPartial->partial_trm;
            } elseif ($tempPartial) {
                $plannedDate = $tempPartial->partial_dispatch_date;
                $partialTrm  = null;
            } else {
                continue; // skip orders without planned date
            }

            if ($plannedDate < $startDate || $plannedDate > $endDate) {
                continue;
            }

            if (!$isSample) {
                $price = ($first->order_product_price > 0) ? $first->order_product_price : ($first->product_price ?? 0);
                $trm   = 4000.0;
                if ($realPartial && !empty($partialTrm) && $partialTrm >= 3400) {
                    $trm = (float) $partialTrm;
                } elseif (isset($trmData[$plannedDate])) {
                    $trm = (float) $trmData[$plannedDate];
                } elseif (!empty($first->order_trm) && $first->order_trm >= 3400) {
                    $trm = (float) $first->order_trm;
                }

                $plannedUsd += $price * $qty;
                $plannedCop += $price * $qty * $trm;
            }

            $ordersSet[$orderId] = true;
        }
        $plannedOrdersCount = count($ordersSet);

        // OCs con al menos un despacho real en el período — misma lógica que DashboardController::calculateExecutiveStats()
        $ordersCreatedQuery = DB::table('purchase_orders as po')
            ->join('purchase_order_product as pop', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'po.order_creation_date', '=', 'td.date')
            ->whereExists(function ($q) use ($startDate, $endDate) {
                $q->select(DB::raw(1))
                    ->from('partials as pt')
                    ->whereColumn('pt.product_order_id', 'pop.id')
                    ->where('pt.type', 'real')
                    ->whereNotNull('pt.dispatch_date')
                    ->whereBetween('pt.dispatch_date', [$startDate, $endDate]);
            })
            ->where('pop.muestra', '=', 0)
            ->selectRaw("
                COUNT(DISTINCT po.id) as total_orders,
                SUM(CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END * pop.quantity) as value_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pop.quantity *
                    (CASE
                        WHEN po.trm >= 3400 THEN po.trm
                        WHEN td.value IS NOT NULL THEN td.value
                        ELSE 4000
                    END)
                ) as value_cop
            ")
            ->first();

        $totalOrdersCreated  = (int)   ($ordersCreatedQuery->total_orders ?? 0);
        $ordersValueUsd      = (float) ($ordersCreatedQuery->value_usd    ?? 0);
        $ordersValueCop      = (float) ($ordersCreatedQuery->value_cop    ?? 0);

        // Status counts — de las OCs con despacho real en el período
        $statusCounts = DB::table('purchase_orders')
            ->whereExists(function ($q) use ($startDate, $endDate) {
                $q->select(DB::raw(1))
                    ->from('partials as pt')
                    ->join('purchase_order_product as pop2', 'pt.product_order_id', '=', 'pop2.id')
                    ->whereColumn('pop2.purchase_order_id', 'purchase_orders.id')
                    ->where('pt.type', 'real')
                    ->whereNotNull('pt.dispatch_date')
                    ->whereBetween('pt.dispatch_date', [$startDate, $endDate]);
            })
            ->selectRaw("
                SUM(CASE WHEN status = 'pending'        THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing'     THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'parcial_status' THEN 1 ELSE 0 END) as parcial_status,
                SUM(CASE WHEN status = 'completed'      THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN is_new_win = 1            THEN 1 ELSE 0 END) as new_win
            ")
            ->first();

        $ordersPending        = (int) ($statusCounts->pending        ?? 0);
        $ordersProcessing     = (int) ($statusCounts->processing     ?? 0);
        $ordersParcialStatus  = (int) ($statusCounts->parcial_status ?? 0);
        $ordersCompleted      = (int) ($statusCounts->completed      ?? 0);
        $ordersNewWin         = (int) ($statusCounts->new_win        ?? 0);
        $ordersCommercial     = $dailyStats->sum('orders_commercial');
        $ordersSample         = $dailyStats->sum('orders_sample');
        $ordersMixed          = $dailyStats->sum('orders_mixed');

        // Recaudos del período
        $recaudos = DB::table('recaudos')
            ->whereBetween('fecha_recaudo', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereNotNull('fecha_recaudo')
            ->where('valor_cancelado', '>', 0)
            ->sum('valor_cancelado');

        $recaudosCount = DB::table('recaudos')
            ->whereBetween('fecha_recaudo', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->whereNotNull('fecha_recaudo')
            ->where('valor_cancelado', '>', 0)
            ->count();

        // Average TRM
        $validTrm = $dailyStats->where('average_trm', '>', 0);
        $avgTrm   = $validTrm->count() > 0 ? round($validTrm->avg('average_trm'), 2) : 4000;

        // Stale orders (processing > 7 days)
        $cutoff = Carbon::now()->subDays(7)->toDateString();
        $stale  = DB::table('purchase_orders')
            ->where('status', 'processing')
            ->where('order_creation_date', '<=', $cutoff)
            ->selectRaw('COUNT(*) as count, MIN(order_creation_date) as oldest_date')
            ->first();

        // Collection coverage
        // Cartera — snapshot más reciente (misma lógica que CarteraQuery)
        $today = Carbon::now('America/Bogota')->toDateString();
        $latestCarteraDate = (string) (DB::table('cartera')->max('fecha_cartera') ?? $today);

        $carteraBase = DB::table('cartera as car')
            ->leftJoin('recaudos as r', 'r.numero_factura', '=', 'car.documento')
            ->where('car.fecha_cartera', '=', $latestCarteraDate);

        $saldoCartera = (float) DB::table('cartera')
            ->where('fecha_cartera', $latestCarteraDate)
            ->sum(DB::raw("CAST(saldo_contable AS DECIMAL(20,2))"));

        // Deuda neta (saldo - recaudos totales por factura, mínimo 0)
        $netDebt = (float) DB::table('cartera as car')
            ->leftJoin('recaudos as r', 'r.numero_factura', '=', 'car.documento')
            ->where('car.fecha_cartera', '=', $latestCarteraDate)
            ->groupBy('car.documento', 'car.saldo_contable')
            ->selectRaw("GREATEST(CAST(car.saldo_contable AS DECIMAL(20,2)) - COALESCE(SUM(r.valor_cancelado), 0), 0) as net_debt")
            ->pluck('net_debt')
            ->sum();

        // Deuda vencida (vence < hoy)
        $overdueDebt = (float) DB::table('cartera as car')
            ->leftJoin('recaudos as r', 'r.numero_factura', '=', 'car.documento')
            ->where('car.fecha_cartera', '=', $latestCarteraDate)
            ->where(function ($q) use ($today) {
                $q->where('car.vence', '<', $today)
                  ->orWhere(function ($n) { $n->whereNotNull('car.dias')->where('car.dias', '<', 0); });
            })
            ->groupBy('car.documento', 'car.saldo_contable')
            ->selectRaw("GREATEST(CAST(car.saldo_contable AS DECIMAL(20,2)) - COALESCE(SUM(r.valor_cancelado), 0), 0) as net_debt")
            ->pluck('net_debt')
            ->sum();

        $coverageRate = ($netDebt > 0) ? round(($recaudos / $netDebt) * 100, 2) : null;

        // TRM variation vs prior period
        $start    = Carbon::parse($startDate);
        $end      = Carbon::parse($endDate);
        $days     = $start->diffInDays($end) + 1;
        $prevEnd  = $start->copy()->subDay()->toDateString();
        $prevStart= Carbon::parse($prevEnd)->subDays($days - 1)->toDateString();
        $currentAvgTrm  = DB::table('trm_daily')->whereBetween('date', [$startDate, $endDate])->avg('value');
        $previousAvgTrm = DB::table('trm_daily')->whereBetween('date', [$prevStart, $prevEnd])->avg('value');
        $trmVariation    = ($currentAvgTrm && $previousAvgTrm && $previousAvgTrm > 0)
            ? round($currentAvgTrm - $previousAvgTrm, 2) : null;
        $trmVariationPct = ($trmVariation !== null && $previousAvgTrm > 0)
            ? round(($trmVariation / $previousAvgTrm) * 100, 2) : null;

        // Top 10 products
        $topProducts = DB::table('partials')
            ->join('purchase_order_product as pop', 'partials.product_order_id', '=', 'pop.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->where('partials.type', 'real')
            ->whereNotNull('partials.dispatch_date')
            ->whereBetween('partials.dispatch_date', [$startDate, $endDate])
            ->where('pop.muestra', 0)
            ->groupBy('p.id', 'p.product_name', 'p.code')
            ->selectRaw('p.id, p.product_name as name, p.code as reference, SUM(partials.quantity) as total_units, COUNT(DISTINCT pop.purchase_order_id) as orders_count')
            ->orderByDesc('total_units')
            ->limit(10)
            ->get()
            ->toArray();

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
                'days_count' => $daysCount,
            ],
            'despachos' => [
                'value_usd'              => round($totalUsd, 2),
                'value_cop'              => round($totalCop, 0),
                'total_kilos_dispatched' => round($totalKilosDispatched, 2),
                'products_count'         => (int) $totalProducts,
                'orders_count'           => (int) $totalOrders,
                'daily_avg_usd'          => $daysCount > 0 ? round($totalUsd / $daysCount, 2) : 0,
                'daily_avg_cop'          => $daysCount > 0 ? round($totalCop / $daysCount, 0) : 0,
            ],
            'planeado' => [
                'value_usd'    => round($plannedUsd, 2),
                'value_cop'    => round($plannedCop, 0),
                'orders_count' => $plannedOrdersCount,
            ],
            'ordenes_creadas' => [
                'total'          => (int) $totalOrdersCreated,
                'pending'        => (int) $ordersPending,
                'processing'     => (int) $ordersProcessing,
                'parcial_status' => (int) $ordersParcialStatus,
                'completed'      => (int) $ordersCompleted,
                'new_win'        => (int) $ordersNewWin,
                'commercial'     => (int) $ordersCommercial,
                'sample'         => (int) $ordersSample,
                'mixed'          => (int) $ordersMixed,
                'value_usd'      => round($ordersValueUsd, 2),
                'value_cop'      => round($ordersValueCop, 0),
            ],
            'pendiente' => [
                'value_usd' => round(max(0, $ordersValueUsd - $totalUsd), 2),
                'value_cop' => round(max(0, $ordersValueCop - $totalCop), 0),
            ],
            'recaudos' => [
                'total_cop' => round($recaudos, 0),
                'count'     => (int) $recaudosCount,
            ],
            'cartera' => [
                'snapshot_date'    => $latestCarteraDate,
                'saldo_bruto_cop'  => round($saldoCartera, 0),   // saldo contable bruto del snapshot (COP)
                'deuda_neta_cop'   => round($netDebt, 0),        // saldo - recaudos históricos (COP)
                'deuda_vencida_cop'=> round($overdueDebt, 0),    // porción vencida de la deuda neta (COP)
                'coverage_rate_pct'=> $coverageRate,             // % cubierto por recaudos del período
            ],
            'trm' => [
                'average'          => $avgTrm,
                'current_period'   => $currentAvgTrm ? round($currentAvgTrm, 2) : null,
                'previous_period'  => $previousAvgTrm ? round($previousAvgTrm, 2) : null,
                'variation'        => $trmVariation,
                'variation_pct'    => $trmVariationPct,
            ],
            'stale_orders' => [
                'count'       => (int) ($stale->count ?? 0),
                'oldest_date' => $stale->oldest_date ?? null,
            ],
            'top_productos'  => $topProducts,
            'executive_stats' => $this->buildExecutiveStats($startDate, $endDate),
        ];
    }

    /**
     * Estadísticas por ejecutiva: OC creadas + despachos reales (misma lógica que DashboardController)
     */
    private function buildExecutiveStats(string $startDate, string $endDate): array
    {
        // Normalize: if executive is an email, extract readable name
        $normalizeExec = function (string $exec): string {
            if (!str_contains($exec, '@')) return $exec;
            $prefix = explode('@', $exec)[0];
            return ucwords(str_replace(['.', '_', '-'], ' ', $prefix));
        };

        // Misma lógica que DashboardController::calculateExecutiveStats():
        // OCs que tienen al menos un despacho real con dispatch_date en el período
        $ordersQuery = DB::table('purchase_orders as po')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->join('purchase_order_product as pop', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'po.order_creation_date', '=', 'td.date')
            ->whereExists(function ($q) use ($startDate, $endDate) {
                $q->select(DB::raw(1))
                    ->from('partials as pt')
                    ->whereColumn('pt.product_order_id', 'pop.id')
                    ->where('pt.type', 'real')
                    ->whereNotNull('pt.dispatch_date')
                    ->whereBetween('pt.dispatch_date', [$startDate, $endDate]);
            })
            ->where('pop.muestra', '=', 0)
            ->selectRaw("
                COALESCE(NULLIF(c.executive, ''), 'Sin ejecutiva') as executive,
                COUNT(DISTINCT po.id) as total_orders,
                SUM(pop.quantity) as total_kilos,
                SUM(
                    CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END * pop.quantity
                ) as value_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pop.quantity *
                    COALESCE(NULLIF(po.trm, 0), NULLIF(td.value, 0), 4000)
                ) as value_cop
            ")
            ->groupBy('c.executive')
            ->get();

        $dispatchQuery = DB::table('partials as pt')
            ->join('purchase_order_product as pop', 'pt.product_order_id', '=', 'pop.id')
            ->join('purchase_orders as po', 'pop.purchase_order_id', '=', 'po.id')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'pt.dispatch_date', '=', 'td.date')
            ->where('pt.type', 'real')
            ->whereNotNull('pt.dispatch_date')
            ->whereBetween('pt.dispatch_date', [$startDate, $endDate])
            ->where('pop.muestra', '=', 0)
            ->selectRaw("
                COALESCE(NULLIF(c.executive, ''), 'Sin ejecutiva') as executive,
                COUNT(DISTINCT po.id) as dispatched_orders,
                SUM(pt.quantity) as dispatched_kilos,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity
                ) as dispatched_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pt.quantity *
                    COALESCE(NULLIF(pt.trm, 0), NULLIF(po.trm, 0), NULLIF(td.value, 0), 4000)
                ) as dispatched_cop
            ")
            ->groupBy('c.executive')
            ->get();

        // Merge orders by normalized executive name
        $merged = [];
        foreach ($ordersQuery as $row) {
            $exec = $normalizeExec($row->executive);
            if (!isset($merged[$exec])) {
                $merged[$exec] = ['executive' => $exec, 'total_orders' => 0, 'total_kilos' => 0.0, 'value_usd' => 0.0, 'value_cop' => 0.0];
            }
            $merged[$exec]['total_orders'] += (int)   $row->total_orders;
            $merged[$exec]['total_kilos']  += (float) $row->total_kilos;
            $merged[$exec]['value_usd']    += (float) $row->value_usd;
            $merged[$exec]['value_cop']    += (float) $row->value_cop;
        }

        // Merge dispatches by normalized executive name
        $dispatchMerged = [];
        foreach ($dispatchQuery as $row) {
            $exec = $normalizeExec($row->executive);
            if (!isset($dispatchMerged[$exec])) {
                $dispatchMerged[$exec] = ['dispatched_orders' => 0, 'dispatched_kilos' => 0.0, 'dispatched_usd' => 0.0, 'dispatched_cop' => 0.0];
            }
            $dispatchMerged[$exec]['dispatched_orders'] += (int)   $row->dispatched_orders;
            $dispatchMerged[$exec]['dispatched_kilos']  += (float) $row->dispatched_kilos;
            $dispatchMerged[$exec]['dispatched_usd']    += (float) ($row->dispatched_usd ?? 0);
            $dispatchMerged[$exec]['dispatched_cop']    += (float) $row->dispatched_cop;
        }

        $totalValueCop = array_sum(array_column($merged, 'value_cop'));

        $result = [];
        foreach ($merged as $exec => $row) {
            $dispatch  = $dispatchMerged[$exec] ?? null;
            $valueCop  = $row['value_cop'];
            $valueUsd  = $row['value_usd'];
            $kilos     = $row['total_kilos'];
            $dispCop    = $dispatch ? $dispatch['dispatched_cop']    : 0;
            $dispUsd    = $dispatch ? $dispatch['dispatched_usd']    : 0;
            $dispKilos  = $dispatch ? $dispatch['dispatched_kilos']  : 0;
            $dispOrders = $dispatch ? $dispatch['dispatched_orders'] : 0;

            $result[] = [
                'executive'            => $exec,
                'total_orders'         => $row['total_orders'],
                'value_usd'            => round($valueUsd, 2),
                'value_cop'            => round($valueCop, 0),
                'total_kilos'          => round($kilos, 2),
                'dispatched_orders'    => $dispOrders,
                'dispatched_usd'       => round($dispUsd, 2),
                'dispatched_cop'       => round($dispCop, 0),
                'dispatched_kilos'     => round($dispKilos, 2),
                'participation_pct'    => $totalValueCop > 0 ? round($valueCop / $totalValueCop * 100, 1) : 0,
                'compliance_usd_pct'   => $valueUsd > 0 ? round($dispUsd   / $valueUsd  * 100, 1) : 0,
                'compliance_cop_pct'   => $valueCop  > 0 ? round($dispCop   / $valueCop  * 100, 1) : 0,
                'compliance_kilos_pct' => $kilos     > 0 ? round($dispKilos / $kilos     * 100, 1) : 0,
            ];
        }

        usort($result, fn ($a, $b) => $b['value_cop'] <=> $a['value_cop']);

        return $result;
    }

    /**
     * Convierte el reporte JSON a Markdown estructurado para el prompt de IA.
     * Más legible que JSON crudo, sin perder ningún dato.
     */
    private function normalizeExecutiveName(string $executive): string
    {
        if (!str_contains($executive, '@')) {
            return $executive; // ya es un nombre
        }
        $local = explode('@', $executive)[0]; // "monica.castano"
        $parts = explode('.', $local);
        return implode(' ', array_map('ucfirst', $parts));
    }

    private function buildReportMarkdown(array $report): string
    {
        $stats  = $report['stats']      ?? [];
        $period = $report['period']     ?? [];
        $orders = $report['ordenes_mes'] ?? [];

        $desp  = $stats['despachos']       ?? [];
        $plan  = $stats['planeado']        ?? [];
        $oc    = $stats['ordenes_creadas'] ?? [];
        $pend  = $stats['pendiente']       ?? [];
        $rec   = $stats['recaudos']        ?? [];
        $cart  = $stats['cartera']         ?? [];
        $trm   = $stats['trm']             ?? [];
        $stale = $stats['stale_orders']    ?? [];
        $tops  = $stats['top_productos']   ?? [];
        $execs = $stats['executive_stats'] ?? [];

        $n = fn($v, $d = 0) => number_format((float) $v, $d, '.', ',');

        $md  = "# REPORTE MENSUAL FINEAROM\n";
        $md .= "**Período:** {$period['start_date']} al {$period['end_date']}\n\n";

        // ── RESUMEN FINANCIERO ──────────────────────────────────────────────
        $md .= "## RESUMEN FINANCIERO\n";
        $md .= "| Métrica | USD | COP |\n|---|---:|---:|\n";
        $md .= "| Total OC (con despacho en período) | \${$n($oc['value_usd'] ?? 0, 2)} | \${$n($oc['value_cop'] ?? 0)} |\n";
        $md .= "| Despachado / Facturado | \${$n($desp['value_usd'] ?? 0, 2)} | \${$n($desp['value_cop'] ?? 0)} |\n";
        $md .= "| Pendiente por facturar | \${$n($pend['value_usd'] ?? 0, 2)} | \${$n($pend['value_cop'] ?? 0)} |\n";
        $md .= "| Planeado en el período | \${$n($plan['value_usd'] ?? 0, 2)} | \${$n($plan['value_cop'] ?? 0)} |\n\n";

        $md .= "**Kilos despachados totales:** {$n($desp['total_kilos_dispatched'] ?? 0, 2)} kg";
        $md .= " | **Líneas de producto despachadas (≠ kilos):** " . ($desp['products_count'] ?? 0);
        $md .= " | **Órdenes despachadas:** " . ($desp['orders_count'] ?? 0) . "\n\n";

        // ── ESTADO DE ÓRDENES ───────────────────────────────────────────────
        $md .= "## ÓRDENES CREADAS EN EL PERÍODO\n";
        $md .= "| Estado | Cantidad |\n|---|---:|\n";
        $md .= "| **Total** | " . ($oc['total'] ?? 0) . " |\n";
        $md .= "| Pendientes (pending) | " . ($oc['pending'] ?? 0) . " |\n";
        $md .= "| En proceso (processing) | " . ($oc['processing'] ?? 0) . " |\n";
        $md .= "| Despacho parcial (parcial_status) | " . ($oc['parcial_status'] ?? 0) . " |\n";
        $md .= "| Completadas (completed) | " . ($oc['completed'] ?? 0) . " |\n";
        $md .= "| New win | " . ($oc['new_win'] ?? 0) . " |\n\n";

        // ── CARTERA ─────────────────────────────────────────────────────────
        $md .= "## CARTERA (snapshot: " . ($cart['snapshot_date'] ?? 'N/A') . ") — todos los valores en COP\n";
        $md .= "| Campo | Valor COP |\n|---|---:|\n";
        $md .= "| Saldo bruto contable | \${$n($cart['saldo_bruto_cop'] ?? 0)} |\n";
        $md .= "| Deuda neta (saldo − recaudos históricos) | \${$n($cart['deuda_neta_cop'] ?? 0)} |\n";
        $md .= "| Deuda vencida | \${$n($cart['deuda_vencida_cop'] ?? 0)} |\n";
        $cov = $cart['coverage_rate_pct'] !== null ? ($cart['coverage_rate_pct'] . '%') : 'N/A';
        $md .= "| Cobertura por recaudos del período | {$cov} |\n\n";

        // ── RECAUDOS ────────────────────────────────────────────────────────
        $md .= "## RECAUDOS DEL PERÍODO\n";
        $md .= "- **Total recaudado:** \${$n($rec['total_cop'] ?? 0)} COP\n";
        $md .= "- **Número de recaudos:** " . ($rec['count'] ?? 0) . "\n\n";

        // ── TRM ─────────────────────────────────────────────────────────────
        $trmValues = array_filter(array_column($orders, 'trm'), fn($v) => (float)$v > 0);
        $trmMin    = $trmValues ? $n(min($trmValues), 2) : null;
        $trmMax    = $trmValues ? $n(max($trmValues), 2) : null;
        $md .= "## TRM\n";
        $md .= "- **TRM promedio del período:** \${$n($trm['average'] ?? 0, 2)} COP/USD\n";
        if ($trmMin && $trmMax) {
            $md .= "- **TRM mínima / máxima:** \${$trmMin} / \${$trmMax} COP/USD\n";
        }
        if (($trm['variation'] ?? null) !== null) {
            $sign = ($trm['variation'] >= 0) ? '+' : '';
            $md .= "- **Variación vs período anterior:** {$sign}{$n($trm['variation'], 2)} ({$sign}{$trm['variation_pct']}%)\n";
        }
        $md .= "\n";

        // ── ÓRDENES ESTANCADAS ──────────────────────────────────────────────
        if (($stale['count'] ?? 0) > 0) {
            $md .= "## ÓRDENES ESTANCADAS (en proceso > 7 días)\n";
            $md .= "- **Cantidad:** " . $stale['count'] . "\n";
            $md .= "- **Más antigua creada el:** " . ($stale['oldest_date'] ?? 'N/A') . "\n\n";
        }

        // ── TOP PRODUCTOS ───────────────────────────────────────────────────
        $md .= "## TOP 10 PRODUCTOS DESPACHADOS (por kilos)\n";
        $md .= "| # | Producto | Referencia | Kilos despachados | # Órdenes |\n|---|---|---|---:|---:|\n";
        foreach ($tops as $i => $p) {
            $p    = (array) $p;
            $md  .= "| " . ($i + 1) . " | " . ($p['name'] ?? '') . " | " . ($p['reference'] ?? '') .
                    " | " . $n($p['total_units'] ?? 0, 2) . " | " . ($p['orders_count'] ?? 0) . " |\n";
        }
        $md .= "\n";

        // ── ESTADÍSTICAS POR EJECUTIVA ──────────────────────────────────────
        $md .= "## ESTADÍSTICAS POR EJECUTIVA\n";
        $md .= "| Ejecutiva | OC | Valor USD | Valor COP | $/kg USD | Kilos | Part.% | OC desp. | Desp. USD | Desp. COP | Desp. kg | Cump.% USD |\n";
        $md .= "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|\n";
        foreach ($execs as $e) {
            $kilos    = (float) $e['total_kilos'];
            $valueUsd = (float) $e['value_usd'];
            $dispUsd  = (float) ($e['dispatched_usd'] ?? 0);
            $pxkg     = $kilos > 0 ? $n($valueUsd / $kilos, 2) : '—';
            $md .= "| {$e['executive']}";
            $md .= " | {$e['total_orders']}";
            $md .= " | \${$n($valueUsd, 2)}";
            $md .= " | \${$n($e['value_cop'])}";
            $md .= " | \${$pxkg}";
            $md .= " | {$n($kilos, 2)}";
            $md .= " | {$e['participation_pct']}%";
            $md .= " | {$e['dispatched_orders']}";
            $md .= " | \${$n($dispUsd, 2)}";
            $md .= " | \${$n($e['dispatched_cop'])}";
            $md .= " | {$n($e['dispatched_kilos'], 2)}";
            $cumpl = $e['compliance_usd_pct'] ?? $e['compliance_cop_pct'];
            $md .= " | {$cumpl}%";
            $md .= " |\n";
        }
        $md .= "\n";

        // ── RESUMEN POR CLIENTE ─────────────────────────────────────────────
        $clientMap = [];
        foreach ($orders as $o) {
            $ckey = ($o['cliente'] ?? '') . '||' . ($o['nit'] ?? '');
            if (!isset($clientMap[$ckey])) {
                $clientMap[$ckey] = [
                    'cliente'    => $o['cliente']   ?? '',
                    'nit'        => $o['nit']        ?? '',
                    'ejecutiva'  => $o['ejecutivo']  ?? '',
                    'ocs'        => 0,
                    'value_usd'  => 0.0,
                    'value_cop'  => 0.0,
                    'kilos'      => 0.0,
                ];
            }
            $clientMap[$ckey]['ocs']++;
            $clientMap[$ckey]['value_usd'] += (float)($o['total_usd'] ?? 0);
            $clientMap[$ckey]['value_cop'] += (float)($o['total_cop'] ?? 0);
            foreach ($o['productos'] ?? [] as $p) {
                if (empty($p['es_muestra'])) {
                    $clientMap[$ckey]['kilos'] += (float)($p['cantidad'] ?? 0);
                }
            }
        }
        usort($clientMap, fn($a, $b) => $b['value_usd'] <=> $a['value_usd']);
        $md .= "## RESUMEN POR CLIENTE\n";
        $md .= "| Cliente | NIT | OCs | Valor USD | Valor COP | Kilos | Ejecutiva |\n";
        $md .= "|---|---|---:|---:|---:|---:|---|\n";
        foreach ($clientMap as $c) {
            $md .= "| {$c['cliente']} | {$c['nit']} | {$c['ocs']} | \${$n($c['value_usd'], 2)} | \${$n($c['value_cop'])} | {$n($c['kilos'], 2)} | {$c['ejecutiva']} |\n";
        }
        $md .= "\n";

        // ── TOP 10 PRODUCTOS PEDIDOS ─────────────────────────────────────────
        $pedidosMap = [];
        foreach ($orders as $o) {
            $cons = $o['consecutivo'] ?? '';
            foreach ($o['productos'] ?? [] as $p) {
                if (!empty($p['es_muestra'])) continue;
                $pname = $p['producto'] ?? '';
                if (!isset($pedidosMap[$pname])) {
                    $pedidosMap[$pname] = ['producto' => $pname, 'kilos' => 0.0, 'ocs' => []];
                }
                $pedidosMap[$pname]['kilos'] += (float)($p['cantidad'] ?? 0);
                $pedidosMap[$pname]['ocs'][$cons] = true;
            }
        }
        usort($pedidosMap, fn($a, $b) => $b['kilos'] <=> $a['kilos']);
        $top10ped = array_slice($pedidosMap, 0, 10);
        $md .= "## TOP 10 PRODUCTOS PEDIDOS (por kilos en OCs del período)\n";
        $md .= "| # | Producto | Kilos pedidos | # OCs |\n|---|---|---:|---:|\n";
        foreach ($top10ped as $i => $p) {
            $md .= "| " . ($i + 1) . " | {$p['producto']} | {$n($p['kilos'], 2)} | " . count($p['ocs']) . " |\n";
        }
        $md .= "\n";

        // ── NEW WIN CONSOLIDADO ──────────────────────────────────────────────
        $newWinRows = [];
        foreach ($orders as $o) {
            $cons    = $o['consecutivo'] ?? '';
            $exec    = $o['ejecutivo']   ?? '';
            $cliente = $o['cliente']     ?? '';
            foreach ($o['productos'] ?? [] as $p) {
                if (!empty($p['new_win'])) {
                    $newWinRows[] = [
                        'oc'          => $cons,
                        'producto'    => $p['producto']    ?? '',
                        'kilos'       => (float)($p['cantidad']   ?? 0),
                        'precio_usd'  => (float)($p['precio_usd'] ?? 0),
                        'ejecutiva'   => $exec,
                        'cliente'     => $cliente,
                    ];
                }
            }
        }
        usort($newWinRows, fn($a, $b) => $b['precio_usd'] <=> $a['precio_usd']);
        if (!empty($newWinRows)) {
            $md .= "## NEW WIN DEL PERÍODO\n";
            $md .= "| OC | Producto | Kilos | $/kg USD | Ejecutiva | Cliente |\n";
            $md .= "|---|---|---:|---:|---|---|\n";
            foreach ($newWinRows as $nw) {
                $md .= "| {$nw['oc']} | {$nw['producto']} | {$n($nw['kilos'], 2)} | \${$n($nw['precio_usd'], 2)} | {$nw['ejecutiva']} | {$nw['cliente']} |\n";
            }
            $md .= "\n";
        }

        // ── DETALLE DE ÓRDENES DEL MES ──────────────────────────────────────
        $md .= "## DETALLE DE ÓRDENES DEL MES\n\n";
        foreach ($orders as $o) {
            $cons    = $o['consecutivo']    ?? '';
            $estado  = $o['estado']         ?? '';
            $cliente   = $o['cliente']        ?? '';
            $nit       = $o['nit']            ?? '';
            $sucursal  = $o['sucursal']       ?? '';
            $exec      = $o['ejecutivo']      ?? '';
            $ciudad    = $o['ciudad_entrega'] ?? '';
            $fecha   = $o['fecha_despacho'] ?? 'Sin despachar';
            $usd     = $n($o['total_usd']   ?? 0, 2);
            $cop     = $n($o['total_cop']   ?? 0);
            $trm2    = $n($o['trm']         ?? 0, 2);
            $factura = $o['factura']        ?? '';
            $guia    = $o['guia']           ?? '';
            $trans   = $o['transportadora'] ?? '';
            $tags    = [];
            if (!empty($o['es_muestra'])) $tags[] = 'MUESTRA';
            if (!empty($o['new_win']))     $tags[] = 'NEW WIN';
            $tagStr  = $tags ? ' [' . implode(', ', $tags) . ']' : '';

            // Kilos totales de la OC (excluyendo muestras)
            $totalKgOc = 0;
            foreach (($o['productos'] ?? []) as $p) {
                if (empty($p['es_muestra'])) {
                    $totalKgOc += (float)($p['cantidad'] ?? 0);
                }
            }

            $totalUsdOc = (float)($o['total_usd'] ?? 0);
            $usdHeader  = $totalUsdOc > 0 ? " | \${$n($totalUsdOc, 2)} USD" : '';
            $md .= "### OC {$cons}{$tagStr} — {$estado} | Ejecutiva: {$exec} | {$n($totalKgOc, 2)} kg{$usdHeader}\n";
            $nitStr      = $nit      ? " | **NIT:** {$nit}"           : '';
            $sucursalStr = $sucursal ? " | **Sucursal:** {$sucursal}" : '';
            $md .= "- **Cliente:** {$cliente}{$nitStr}{$sucursalStr} | **Ciudad:** {$ciudad}\n";
            $md .= "- **Despacho:** {$fecha}";
            if ($factura) $md .= " | **Factura:** {$factura} | **Guía:** {$guia} | **Trans.:** {$trans}";
            $md .= "\n";
            if (($o['total_usd'] ?? 0) > 0) {
                $md .= "- **Valor:** \${$usd} USD / \${$cop} COP (TRM: {$trm2})\n";
            }

            $prods = $o['productos'] ?? [];
            if (!empty($prods)) {
                $md .= "- **Productos:**\n";
                foreach ($prods as $p) {
                    $pname  = $p['producto']     ?? '';
                    $qty    = $n($p['cantidad']   ?? 0, 2);
                    $price  = $n($p['precio_usd'] ?? 0, 2);
                    $ptot   = $n($p['subtotal_usd'] ?? 0, 2);
                    $muStr  = !empty($p['es_muestra']) ? ' (muestra)' : '';
                    $nwStr  = !empty($p['new_win'])    ? ' [NEW WIN]' : '';
                    $md    .= "  - {$pname}{$muStr}{$nwStr}: {$qty} kg @ \${$price} = \${$ptot} USD\n";
                }
            }
            $md .= "\n";
        }

        return $md;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROMPT V2 — versión comprimida (~40% menos tokens)
    // Para activar: en chatStartV2, reemplazar $prompt = ... por
    //   $prompt = $this->buildChatPromptV2($startDate, $endDate, $trmHoyStr);
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Prompt optimizado (~8-9k tokens vs ~14k del v1).
     * Elimina redundancias entre secciones y comprime prosa en reglas directas.
     */
    private function buildChatPromptV2(string $startDate, string $endDate, string $trmHoyStr): string
    {
        return
            "Eres un asistente de análisis comercial para Finearom (Colombia, fragancias/materias primas).\n" .
            "PERÍODO: {$startDate} al {$endDate}. TRM hoy: {$trmHoyStr} COP/USD.\n\n" .

            "## ROLES\n" .
            "- **FRANCY** (pedidos): crea OC → estado pending. Campos: cliente, productos, kg, precio USD/kg, TRM, fecha entrega, is_muestra, is_new_win.\n" .
            "- **MARLON** (logística): revisa pending → pone observaciones + fechas estimadas (partials type=temporal) → mueve a processing. OC processing >7 días sin despacho = REZAGADA.\n" .
            "- **ALEXA** (despacho): registra despachos reales → crea partials type=real (dispatch_date, invoice_number, tracking_number, transporter, trm, kg) → OC pasa a parcial_status (parcial) o completed (todo).\n" .
            "- **EJECUTIVAS**: Monica Castano, Juliana Pardo, Claudia Cueter, Maria Ortega, Camila Quintero, Daniela Aristizabal. Campo: clients.executive (email). Para mostrar: REPLACE(SUBSTRING_INDEX(executive,'@',1),'.',' ').\n\n" .

            "## FLUJO OC\n" .
            "pending (Francy) → processing (Marlon) → parcial_status|completed (Alexa) | cancelled (Francy/Marlon solo)\n\n" .

            "## REGLAS CRÍTICAS DE FILTROS\n" .
            "1. **Status vs fecha creación**: WHERE po.status IN ('pending','processing','parcial_status') → NUNCA agregar order_creation_date BETWEEN. Solo agregar BETWEEN cuando el usuario pide órdenes *creadas* en un período.\n" .
            "2. **Despachos del período**: SIEMPRE filtrar par.type='real' AND par.dispatch_date BETWEEN fechas AND par.deleted_at IS NULL AND pop.muestra=0.\n" .
            "3. **Soft delete partials**: SIEMPRE par.deleted_at IS NULL. Alexa borra+recrea partials al actualizar; sin este filtro hay duplicados.\n" .
            "4. **Muestras**: excluir con pop.muestra=0 y po.is_muestra=0 en cálculos de valor/kilos.\n" .
            "5. **Cartera snapshot**: SIEMPRE fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera). Sin esto traes histórico.\n" .
            "6. **TRM cascada**: COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date<=CURDATE() ORDER BY date DESC LIMIT 1), 4000). El +0 es necesario (trm es string en MariaDB).\n" .
            "7. **Precio efectivo**: COALESCE(NULLIF(pop.price,0), p.price, 0). Siempre requiere JOIN products p.\n" .
            "8. **Kilos pedidos vs despachados**: pop.quantity=pedido, par.quantity=real despachado. NUNCA mezclar roles.\n" .
            "9. **Cartera saldos**: saldo_contable/saldo_vencido son strings con punto decimal. CAST(saldo_contable AS DECIMAL(15,2)). NUNCA REPLACE.\n" .
            "10. **Ejecutiva en cartera**: filtrar por clients.executive (JOIN por nit), NO por cartera.vendedor (es el de SIIGO, puede diferir).\n\n" .

            "## MÉTRICAS — CÓMO CALCULAR\n" .
            "- **Despachado período**: part. type=real, dispatch_date BETWEEN, deleted_at IS NULL, muestra=0.\n" .
            "- **Creado período**: order_creation_date BETWEEN en purchase_orders (sin tocar partials).\n" .
            "- **Stats ejecutiva**: partir SIEMPRE desde partials con dispatch_date en período → JOIN pop, po, clients. GROUP BY c.executive. Incluye OCs de cualquier mes.\n" .
            "- **Fill rate**: SUM(par.quantity)/SUM(pop.quantity)*100 (en kg). Base: partials reales del período.\n" .
            "- **Cumplimiento USD**: despachado_usd/total_oc_usd*100. despachado=par.qty*precio, total_oc=pop.qty*precio.\n" .
            "- **Valor COP**: qty * precio * TRM_cascada.\n" .
            "- **Pipeline**: SUM partials type=temporal, deleted_at IS NULL (valor planeado sin despachar).\n" .
            "- **New win OC**: COUNT DISTINCT po.id WHERE is_new_win=1 AND order_creation_date BETWEEN.\n" .
            "- **Rezagadas**: status=processing AND order_creation_date <= DATE_SUB(CURDATE(), INTERVAL 7 DAY).\n" .
            "- **Lead time real**: DATEDIFF(MIN(par.dispatch_date type=real), po.order_creation_date) por OC.\n" .
            "- **On-time por línea**: par.dispatch_date <= pop.delivery_date. NUNCA usar po.required_delivery_date ni pop.required_delivery_date (no existe en pop).\n" .
            "- **Deuda neta cartera**: SUM(saldo_contable) - SUM(recaudos.valor_cancelado) por nit. JOIN recaudos ON numero_factura=cartera.documento.\n" .
            "- **Kilos pendientes en parcial_status**: SUM(pop.quantity) - SUM(par.quantity type=real, deleted_at IS NULL).\n\n" .

            "## TÉRMINOS ESPAÑOL → BD\n" .
            "pendiente=pending | en proceso/procesando=processing | parcial=parcial_status | completada/despachada=completed | cancelada=cancelled\n\n" .

            "## MAPEO CARTERA\n" .
            "dias>0=por vencer | dias<0=vencida hace ABS(dias) días. Para mora: ABS(ca.dias) WHERE dias<0.\n" .
            "catera_type: nacional | internacional.\n\n" .

            "## ESQUEMA BD\n" . $this->getDbSchemaCompact($startDate, $endDate) . "\n\n" .

            "## FORMATO DE RESPUESTA — OBLIGATORIO\n" .
            "Responde SIEMPRE con un objeto JSON válido, sin texto antes ni después, sin wrapper de backticks.\n" .
            "Estructura: {\"html\":\"<p>explicación</p>\",\"sql\":\"SELECT...\",\"showing\":\"campo1, campo2\",\"available\":\"campo3\"}\n" .
            "- \"html\": HTML limpio. Sin markdown, sin LaTeX.\n" .
            "- \"sql\": query completa sin escapar. null si no hay query.\n" .
            "- \"showing\"/\"available\": nombres en español legibles. Omitir si sql=null.\n" .
            "- GROUP BY: todos los campos no-agregados del SELECT en GROUP BY. Agrupa por c.executive (no alias).\n" .
            "- Nunca id en SELECT. Siempre NIT junto al nombre del cliente.\n" .
            "- Aliases: client_name, ejecutiva, kilos, valor_usd, valor_cop, ocs, fill_rate_pct, fecha_despacho, numero_oc.\n" .
            "- Bienvenida: html con saludo + período + 3-4 ejemplos. sql=null.";
    }

    /**
     * Esquema comprimido para prompt v2. Mantiene todos los campos críticos,
     * elimina comentarios redundantes con las reglas ya explicadas en el prompt.
     */
    private function getDbSchemaCompact(string $startDate, string $endDate): string
    {
        return <<<SCHEMA
### clients
id, client_name, nit(UNIQUE), executive(email), status(active|inactive), city, operation_type(nacional|extranjero)
client_type: AA>A>B>C (prioridad/lead time) | pareto/balance/none (portafolio legacy)
lead_time(INT días), payment_method(1=Contado,2=Crédito), payment_day, credit_term
purchase_frequency, estimated_monthly_quantity, iva, retefuente, reteiva, ica
portfolio_contact_email, dispatch_confirmation_email, purchasing_contact_email, logistics_contact_email

### branch_offices
id, client_id→clients.id, name, nit, delivery_address, delivery_city

### purchase_orders
id, client_id→clients.id, order_consecutive(ej:2258-4500302325)
status: pending|processing|parcial_status|completed|cancelled
order_creation_date(date), dispatch_date(date—estimado Marlon), required_delivery_date(date—solicitado cliente)
trm, is_new_win(0/1), is_muestra(0/1), observations, created_at

### purchase_order_product
id, purchase_order_id→purchase_orders.id, product_id→products.id
quantity(kg pedidos), price(USD/kg—puede ser NULL en OCs antiguas), new_win(0/1), muestra(0/1)
delivery_date(date—fecha acordada por línea, usar para on-time), branch_office_id→branch_offices.id
cierre_cartera(DATETIME), status
⚠ required_delivery_date NO existe en esta tabla. On-time = par.dispatch_date <= pop.delivery_date.

### products
id, code, product_name, price(USD/kg catálogo fallback), client_id→clients.id
categories(JSON array, ej:["Floral","Amaderado"])
Filtrar por categoría: JSON_SEARCH(LOWER(p.categories),'one','%floral%') IS NOT NULL
⚠ productos son POR CLIENTE. Precio negociado → pop.price. Precio efectivo → COALESCE(NULLIF(pop.price,0),p.price,0)

### product_price_history
id, product_id→products.id, price(DECIMAL USD/kg), effective_date, created_by→users.id

### partials (despachos)
id, order_id→purchase_orders.id, product_order_id→purchase_order_product.id, product_id→products.id
quantity(kg), type(temporal=estimado|real=despachado), dispatch_date(date)
trm, invoice_number, pdf_invoice, tracking_number, transporter, deleted_at(soft delete)
⚠ Alexa borra+recrea todos los partials al actualizar → SIEMPRE deleted_at IS NULL

### cartera (snapshot SIIGO)
id, nit, nombre_empresa, fecha_cartera(date snapshot—filtrar MAX)
documento(VARCHAR nro.factura—join recaudos.numero_factura), fecha(emisión), vence(vencimiento)
dias(INT: >0=por vencer, <0=vencida), saldo_contable(STRING COP), saldo_vencido(STRING COP)
vendedor, nombre_vendedor(SIIGO—puede diferir de clients.executive), catera_type(nacional|internacional|NULL)
ciudad, cuenta, descripcion_cuenta

### recaudos (pagos COP)
id, nit(BIGINT), cliente, fecha_recaudo(DATETIME), numero_factura(VARCHAR)
valor_cancelado(DECIMAL COP), fecha_vencimiento(DATETIME), dias(mora al pagar), numero_recibo

### trm_daily
id, date, value(DECIMAL COP/USD), is_weekend(0/1), is_holiday(0/1)

### order_statistics (snapshot diario pre-calculado—usar para tendencias)
date(UNIQUE), total_orders_created, orders_pending, orders_processing, orders_completed, orders_parcial_status, orders_new_win
total_orders_value_usd/cop, dispatched_orders_count, commercial_dispatched_value_usd/cop
orders_commercial/sample/mixed, pending_dispatch_value_usd/cop, planned_dispatch_value_usd/cop, planned_orders_count
dispatch_fulfillment_rate_usd(%), avg_days_order_to_first_dispatch
unique_clients_with_orders/dispatches, extended_stats(JSON)

## RELACIONES
clients→purchase_orders (client_id) | purchase_orders→purchase_order_product (purchase_order_id)
purchase_order_product→products (product_id) | purchase_order_product→branch_offices (branch_office_id)
purchase_orders→partials (order_id) | partials→purchase_order_product (product_order_id)
clients→branch_offices (client_id) | clients.nit↔cartera.nit | clients.nit↔recaudos.nit
cartera.documento↔recaudos.numero_factura | products→product_price_history (product_id)

## QUERIES DE REFERENCIA

Stats ejecutiva (base=partials período):
SELECT REPLACE(SUBSTRING_INDEX(c.executive,'@',1),'.',' ') ejecutiva,
  SUM(pop.quantity) kilos_pedidos, SUM(par.quantity) kilos_despachados,
  ROUND(SUM(par.quantity*COALESCE(NULLIF(pop.price,0),p.price,0)),2) despachado_usd,
  ROUND(SUM(par.quantity*COALESCE(NULLIF(pop.price,0),p.price,0))/NULLIF(SUM(pop.quantity*COALESCE(NULLIF(pop.price,0),p.price,0)),0)*100,1) cumplimiento_pct
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id=pop.id AND pop.muestra=0
JOIN products p ON pop.product_id=p.id
JOIN purchase_orders po ON par.order_id=po.id
JOIN clients c ON po.client_id=c.id
WHERE par.type='real' AND par.dispatch_date BETWEEN '{$startDate}' AND '{$endDate}' AND par.deleted_at IS NULL
GROUP BY c.executive ORDER BY despachado_usd DESC;

Cartera agrupada por cliente:
SELECT ca.nit, ca.nombre_empresa,
  SUM(CAST(ca.saldo_contable AS DECIMAL(15,2))) saldo_cop,
  SUM(CAST(ca.saldo_vencido AS DECIMAL(15,2))) vencido_cop
FROM cartera ca WHERE ca.fecha_cartera=(SELECT MAX(fecha_cartera) FROM cartera)
GROUP BY ca.nit, ca.nombre_empresa ORDER BY saldo_cop DESC;

Facturas vencidas:
SELECT nit, nombre_empresa, documento, ABS(dias) dias_mora, CAST(saldo_contable AS DECIMAL(15,2)) saldo_cop
FROM cartera WHERE fecha_cartera=(SELECT MAX(fecha_cartera) FROM cartera) AND dias<0 ORDER BY dias ASC;

Deuda neta (saldo menos pagos):
SELECT ca.nit, ca.nombre_empresa,
  GREATEST(SUM(CAST(ca.saldo_contable AS DECIMAL(15,2)))-COALESCE(SUM(r.valor_cancelado),0),0) deuda_neta
FROM cartera ca LEFT JOIN recaudos r ON r.numero_factura=ca.documento AND r.nit=ca.nit
WHERE ca.fecha_cartera=(SELECT MAX(fecha_cartera) FROM cartera)
GROUP BY ca.nit, ca.nombre_empresa ORDER BY deuda_neta DESC;

Evolución diaria (snapshot—eficiente):
SELECT date, commercial_dispatched_value_usd, dispatched_orders_count, dispatch_fulfillment_rate_usd fill_rate_pct
FROM order_statistics WHERE date BETWEEN '{$startDate}' AND '{$endDate}' ORDER BY date;
SCHEMA;
    }

    private function getDbSchema(): string
    {
        return <<<'SCHEMA'
## TABLAS RELEVANTES

### clients — Clientes
- id PK, client_name, nit (UNIQUE), executive (EMAIL de la ejecutiva), status ('active'|'inactive'), city, operation_type ('nacional'|'extranjero')
- client_type: 'AA'>'A'>'B'>'C' (prioridad comercial/lead time) | 'pareto','balance','none' (portafolio legacy)
- lead_time (INT días) — tiempo de entrega estándar. AA/A=9 días, C=12 días

### branch_offices — Sucursales de clientes
- id PK, client_id FK→clients.id, name, delivery_city
- Cada cliente puede tener múltiples sucursales con distintas ciudades de entrega

### purchase_orders — Órdenes de compra
- id PK, client_id FK→clients.id, order_consecutive (ej: '2258-4500302325'), status ('pending'|'processing'|'completed'|'cancelled'|'parcial_status'), order_creation_date (date), dispatch_date (date — fecha estimada puesta por Marlon), required_delivery_date (date — fecha solicitada por el cliente), trm, is_new_win (0/1), is_muestra (0/1)
- pending = Francy creó, esperando Marlon | processing = Marlon revisó, en preparación | parcial_status = Alexa despachó parcialmente | completed = Alexa despachó todo
- LEAD TIME por OC = DATEDIFF(fecha_real_despacho, order_creation_date). Comparar con clients.lead_time para saber si fue en tiempo.
- ON TIME (por línea) = par.dispatch_date <= pop.delivery_date. Numerador y denominador deben ser LÍNEAS: SUM(CASE WHEN par.dispatch_date <= pop.delivery_date THEN 1 ELSE 0 END) / COUNT(par.id)
- ON TIME (por OC) = la OC se considera a tiempo si TODAS sus líneas fueron despachadas a tiempo. Usar COUNT(DISTINCT CASE WHEN...) / COUNT(DISTINCT po.id)
- ⚠ NUNCA mezcles numerador de líneas con denominador de órdenes — da porcentajes >100%
- NOTA: la ejecutiva se obtiene JOIN clients → clients.executive

### purchase_order_product — Líneas de producto en OCs
- id PK, purchase_order_id FK→purchase_orders.id, product_id FK→products.id, quantity (kg pedidos), price (USD/kg negociado — puede ser NULL en OCs antiguas), new_win (0/1), muestra (0/1), delivery_date (date — fecha de entrega por línea), branch_office_id FK→branch_offices.id
- ⚠ required_delivery_date NO existe en esta tabla. Para on-time usar SIEMPRE pop.delivery_date
- Para precio real usar siempre: COALESCE(NULLIF(pop.price,0), p.price, 0)

### products — Productos
- id PK, code, product_name, price (USD/kg actual — precio catálogo fallback), client_id FK→clients.id, categories (JSON array de categorías — ej: ["Floral","Amaderado"])
- CRÍTICO: los productos son POR CLIENTE (client_id). La misma fragancia puede tener precios distintos por cliente.
- El precio en products es el precio catálogo vigente. Para precio negociado en una OC específica → usar pop.price (puede ser NULL en OCs antiguas)
- Para filtrar por categoría: JSON_SEARCH(LOWER(p.categories), 'one', '%floral%') IS NOT NULL
- Para listar categorías: JSON_UNQUOTE(JSON_EXTRACT(p.categories, '$[0]'))

### product_price_history — Historial de precios
- id PK, product_id FK→products.id, price (DECIMAL USD/kg), effective_date (fecha desde la que aplica, normalmente 1 enero), created_by FK→users.id
- Útil para preguntas como "¿cuál era el precio en enero?" o "¿cómo ha cambiado el precio de X?"

### partials — Despachos
- id PK, order_id FK→purchase_orders.id, product_order_id FK→purchase_order_product.id, quantity (kg), type ('temporal'|'real'), dispatch_date (date), trm, invoice_number, tracking_number, transporter, deleted_at (soft delete)
- CRÍTICO: para análisis de despachos del período SIEMPRE filtrar: type = 'real' AND dispatch_date BETWEEN ... AND deleted_at IS NULL AND pop.muestra = 0

### cartera — Cartera (COP, importada de sistema contable SIIGO)
- id PK, nit, nombre_empresa, fecha_cartera (date del snapshot — agrupa todas las filas del mismo import)
- documento (VARCHAR) — número de factura. Se une con recaudos.numero_factura para ver pagos de esa factura
- vence (DATE) — fecha de vencimiento de la factura
- dias (INT) — días de cartera: POSITIVO = factura AÚN no vencida (días restantes), NEGATIVO = factura VENCIDA hace N días
  → Para días de mora usar: ABS(ca.dias) WHERE ca.dias < 0. NUNCA usar DATEDIFF(CURDATE(), fecha_cartera) — eso es la antigüedad del snapshot, no los días de mora.
- saldo_contable (STRING COP) — saldo total de la factura
- saldo_vencido (STRING COP) — porción ya vencida del saldo
- vendedor, nombre_vendedor — vendedor en SIIGO (≠ clients.executive, NO usar para filtrar por ejecutiva)
- catera_type (VARCHAR) — 'nacional' | 'internacional' | NULL
- CRÍTICO: saldo_contable y saldo_vencido son strings con PUNTO como separador decimal (ej: '26857379.12', '-256309.65'). Ya son decimales normales.
  → Para operar: CAST(saldo_contable AS DECIMAL(15,2)) — sin REPLACE, el punto ya es el decimal
  → NUNCA uses REPLACE para quitar puntos — destruyes el separador decimal y multiplicas el valor por 10
- CRÍTICO: NUNCA filtrar con fecha hardcodeada. SIEMPRE: fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)
- CRÍTICO: para deuda NETA real = saldo_contable MENOS lo pagado en recaudos: MAX(saldo - SUM(recaudos), 0)
- Para ordenar: ORDER BY CAST(saldo_contable AS DECIMAL(15,2)) DESC
- Facturas VENCIDAS: WHERE dias < 0 OR vence < CURDATE()
- Facturas POR VENCER: WHERE dias >= 0 AND vence >= CURDATE()

### recaudos — Pagos recibidos (COP)
- nit (BIGINT), cliente, fecha_recaudo (DATETIME), numero_factura (VARCHAR — join con cartera.documento), valor_cancelado (DECIMAL COP)
- Para deuda neta: SUM(cartera.saldo_contable) - SUM(recaudos.valor_cancelado) por nit

### trm_daily — TRM diaria
- date (date), value (decimal COP/USD)

### order_statistics — NO usar para consultas del chat
- Se genera una sola vez al día y sus valores COP/TRM son poco confiables.
- Para cualquier consulta de tendencias, evolución o histórico: ir directamente a partials, purchase_orders y purchase_order_product.

### executives — Nombres reales de ejecutivas comerciales
- id PK, name (nombre completo), email (join con clients.executive), is_active (0/1)
- ⚠ FUENTE DE VERDAD: para cualquier scorecard o listado de ejecutivas SIEMPRE partir de FROM executives WHERE is_active=1. NUNCA usar DISTINCT clients.executive — ese campo tiene basura (typos, emails múltiples, texto 'executive').
- ⚠ COLLATION: JOIN executives e ON e.email COLLATE utf8mb4_general_ci = c.executive
- ⚠ new_wins por ejecutiva: usar COUNT(DISTINCT CASE WHEN po.is_new_win=1 THEN po.id END) — nunca SUM sin DISTINCT (cuenta líneas no órdenes)

## JOINS CLAVE (sin FK explícita)
- clients.nit ↔ cartera.nit | cartera.documento ↔ recaudos.numero_factura

## CONVERSIÓN DE MONEDA
- Valor COP = quantity * precio * COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000)
- El +0 es necesario porque trm es string en MariaDB. NUNCA uses la TRM de hoy para convertir órdenes.

## NORMALIZACIÓN DE EJECUTIVA
- clients.executive es un email. Para nombre legible: REPLACE(SUBSTRING_INDEX(executive,'@',1),'.',' ')

## QUERIES DE REFERENCIA

Stats por ejecutiva — pedido vs despachado con cumplimiento (BASE = partials del período):
SELECT REPLACE(SUBSTRING_INDEX(c.executive,'@',1),'.',' ') AS ejecutiva,
  c.nit AS nit_cliente,
  SUM(pop.quantity) kilos_pedidos,
  ROUND(SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)), 2) total_oc_usd,
  SUM(par.quantity) kilos_despachados,
  ROUND(SUM(par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)), 2) despachado_usd,
  ROUND(SUM(par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) /
    NULLIF(SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)), 0) * 100, 1) cumplimiento_pct
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id = pop.id AND pop.muestra = 0
JOIN products p ON pop.product_id = p.id
JOIN purchase_orders po ON par.order_id = po.id
JOIN clients c ON po.client_id = c.id
WHERE par.type = 'real'
  AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31'
  AND par.deleted_at IS NULL
GROUP BY c.executive
ORDER BY despachado_usd DESC;
-- NOTA: esta query parte de partials (despachos) e incluye OCs creadas en cualquier mes.
-- NO usar order_creation_date aquí — se perderían ejecutivas con OCs antiguas despachadas en el período.

Participación por ejecutiva en un período (OCs CREADAS, valor USD y COP):
SELECT COALESCE(NULLIF(c.executive,''), 'Sin ejecutiva') ejecutiva,
  COUNT(DISTINCT po.id) ocs,
  SUM(pop.quantity) kilos,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0) * COALESCE(NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000)) valor_cop
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.order_creation_date BETWEEN '2026-03-01' AND '2026-03-31' AND pop.muestra = 0
GROUP BY c.executive ORDER BY valor_cop DESC;

Despachos del período por ejecutiva (valor USD y COP — DESPACHADAS):
SELECT COALESCE(NULLIF(c.executive,''), 'Sin ejecutiva') ejecutiva,
  COUNT(DISTINCT po.id) ocs, SUM(par.quantity) kilos,
  SUM(par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd,
  SUM(par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0) * COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), (SELECT value FROM trm_daily WHERE date <= CURDATE() ORDER BY date DESC LIMIT 1), 4000)) valor_cop
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id = pop.id
JOIN products p ON pop.product_id = p.id
JOIN purchase_orders po ON par.order_id = po.id
JOIN clients c ON po.client_id = c.id
WHERE par.type = 'real' AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31' AND par.deleted_at IS NULL AND pop.muestra = 0
GROUP BY c.executive ORDER BY valor_cop DESC;

Contar órdenes despachadas en un período (mismo criterio que el dashboard):
SELECT COUNT(DISTINCT po.id) ordenes_despachadas
FROM purchase_orders po
WHERE EXISTS (
  SELECT 1 FROM partials par
  JOIN purchase_order_product pop ON par.product_order_id = pop.id
  WHERE par.order_id = po.id AND par.type = 'real'
    AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31'
    AND par.deleted_at IS NULL AND pop.muestra = 0
);

Cartera AGRUPADA por cliente (usar SUM porque hay múltiples facturas por NIT):
SELECT ca.nit, ca.nombre_empresa,
  SUM(CAST(ca.saldo_contable AS DECIMAL(15,2))) saldo_cop,
  SUM(CAST(ca.saldo_vencido AS DECIMAL(15,2))) vencido_cop
FROM cartera ca WHERE ca.fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)
GROUP BY ca.nit, ca.nombre_empresa
ORDER BY saldo_cop DESC;

Deuda neta por cliente (saldo contable menos lo ya pagado en recaudos):
SELECT ca.nit, ca.nombre_empresa,
  SUM(CAST(ca.saldo_contable AS DECIMAL(15,2))) saldo_bruto,
  COALESCE(SUM(r.valor_cancelado),0) total_pagado,
  GREATEST(SUM(CAST(ca.saldo_contable AS DECIMAL(15,2))) - COALESCE(SUM(r.valor_cancelado),0), 0) deuda_neta
FROM cartera ca
LEFT JOIN recaudos r ON r.numero_factura = ca.documento AND r.nit = ca.nit
WHERE ca.fecha_cartera = (SELECT MAX(fecha_cartera) FROM cartera)
GROUP BY ca.nit, ca.nombre_empresa
ORDER BY deuda_neta DESC;

Evolución diaria de despachos del período (usando snapshot pre-calculado — muy eficiente):
SELECT date, commercial_dispatched_value_usd, dispatched_orders_count,
  dispatch_fulfillment_rate_usd fill_rate_pct,
  pending_dispatch_value_usd pipeline_usd
FROM order_statistics
WHERE date BETWEEN '2026-03-01' AND '2026-03-31'
ORDER BY date;

Historial de precios de un producto:
SELECT p.product_name, ph.price precio_usd_kg, ph.effective_date vigente_desde
FROM product_price_history ph
JOIN products p ON ph.product_id = p.id
WHERE p.product_name LIKE '%BOUSCHET%'
ORDER BY ph.effective_date DESC;

Despachos planeados del período (fecha estimada de despacho dentro del rango):
-- "Planeado" = OCs con despacho programado en el período, priorizando: partial real > partial temporal > po.dispatch_date
SELECT po.order_consecutive, c.client_name, po.status,
  COALESCE(
    (SELECT par_r.dispatch_date FROM partials par_r WHERE par_r.order_id = po.id AND par_r.type='real' AND par_r.deleted_at IS NULL ORDER BY par_r.dispatch_date DESC LIMIT 1),
    (SELECT par_t.dispatch_date FROM partials par_t WHERE par_t.order_id = po.id AND par_t.type='temporal' AND par_t.deleted_at IS NULL ORDER BY par_t.dispatch_date DESC LIMIT 1),
    po.dispatch_date
  ) AS fecha_despacho_estimada,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.status IN ('pending','processing','parcial_status')
  AND pop.muestra = 0
  AND COALESCE(
    (SELECT par_r.dispatch_date FROM partials par_r WHERE par_r.order_id = po.id AND par_r.type='real' AND par_r.deleted_at IS NULL ORDER BY par_r.dispatch_date DESC LIMIT 1),
    (SELECT par_t.dispatch_date FROM partials par_t WHERE par_t.order_id = po.id AND par_t.type='temporal' AND par_t.deleted_at IS NULL ORDER BY par_t.dispatch_date DESC LIMIT 1),
    po.dispatch_date
  ) BETWEEN '2026-03-01' AND '2026-03-31'
GROUP BY po.id, c.client_name, po.status
ORDER BY fecha_despacho_estimada;

Lead time real por tipo de cliente (AA/A/B/C):
SELECT c.client_type,
  COUNT(DISTINCT po.id) total_ordenes,
  ROUND(AVG(DATEDIFF(
    (SELECT MIN(par2.dispatch_date) FROM partials par2 WHERE par2.order_id = po.id AND par2.type='real' AND par2.deleted_at IS NULL),
    po.order_creation_date
  ))) avg_dias_reales,
  ROUND(AVG(DATEDIFF(po.required_delivery_date, po.order_creation_date))) avg_dias_solicitados,
  ROUND(100.0 * SUM(CASE WHEN
    (SELECT MIN(par2.dispatch_date) FROM partials par2 WHERE par2.order_id = po.id AND par2.type='real' AND par2.deleted_at IS NULL) <= po.required_delivery_date
    THEN 1 ELSE 0 END) / COUNT(DISTINCT po.id), 1) pct_a_tiempo
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
WHERE po.order_creation_date BETWEEN '2026-03-01' AND '2026-03-31'
  AND c.client_type IN ('AA','A','B','C')
  AND (po.is_muestra = 0 OR po.is_muestra IS NULL)
GROUP BY c.client_type
ORDER BY FIELD(c.client_type,'AA','A','B','C');

Órdenes vencidas / rezagadas (processing sin despachar hace más de 7 días):
SELECT po.order_consecutive, c.client_name, po.status, po.order_creation_date,
  DATEDIFF(CURDATE(), po.order_creation_date) dias_sin_despachar
FROM purchase_orders po JOIN clients c ON po.client_id = c.id
WHERE po.status = 'processing'
  AND po.order_creation_date <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
ORDER BY dias_sin_despachar DESC;

Despachos con ciudad de entrega (usando branch_offices):
SELECT po.order_consecutive, c.client_name, p.product_name,
  bo.delivery_city ciudad_entrega, bo.name sucursal,
  par.quantity kilos_despachados, par.dispatch_date, par.invoice_number
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id = pop.id
JOIN products p ON pop.product_id = p.id
JOIN purchase_orders po ON par.order_id = po.id
JOIN clients c ON po.client_id = c.id
LEFT JOIN branch_offices bo ON pop.branch_office_id = bo.id
WHERE par.type = 'real' AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31'
  AND par.deleted_at IS NULL AND pop.muestra = 0
ORDER BY par.dispatch_date DESC;

## CONSULTAS POR ROL

### FRANCY — Creación de órdenes

Órdenes creadas hoy por Francy (pendientes de revisión):
SELECT po.order_consecutive, c.client_name, po.order_creation_date, po.status,
  SUM(pop.quantity) kilos_pedidos,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.status = 'pending' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date, po.status
ORDER BY po.order_creation_date DESC;

Todas las OC creadas en el período (lo que ingresó Francy):
SELECT po.order_consecutive, c.client_name, po.order_creation_date, po.status,
  po.is_new_win, po.is_muestra,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.order_creation_date BETWEEN '2026-03-01' AND '2026-03-31' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date, po.status, po.is_new_win, po.is_muestra
ORDER BY po.order_creation_date DESC;

### MARLON — Revisión y fechas estimadas de despacho

OC que Marlon tiene pendientes de revisar (status pending):
SELECT po.order_consecutive, c.client_name, po.order_creation_date,
  DATEDIFF(CURDATE(), po.order_creation_date) dias_esperando,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.status = 'pending' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date
ORDER BY dias_esperando DESC;

OC en processing (Marlon revisó, Alexa aún no despacha):
SELECT po.order_consecutive, c.client_name, po.order_creation_date, po.dispatch_date fecha_estimada,
  DATEDIFF(CURDATE(), po.order_creation_date) dias_en_processing,
  (SELECT par_t.dispatch_date FROM partials par_t WHERE par_t.order_id = po.id AND par_t.type='temporal' AND par_t.deleted_at IS NULL ORDER BY par_t.dispatch_date DESC LIMIT 1) fecha_estimada_marlon,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
WHERE po.status = 'processing' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date, po.dispatch_date
ORDER BY dias_en_processing DESC;

### ALEXA — Despachos reales

Lo que Alexa despachó en el período (despachos reales):
SELECT po.order_consecutive, c.client_name, par.dispatch_date, par.invoice_number,
  par.tracking_number, par.transporter, par.quantity kilos,
  par.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0) valor_usd,
  COALESCE(NULLIF(par.trm+0,0), NULLIF(po.trm+0,0), 4000) trm_usada
FROM partials par
JOIN purchase_order_product pop ON par.product_order_id = pop.id
JOIN products p ON pop.product_id = p.id
JOIN purchase_orders po ON par.order_id = po.id
JOIN clients c ON po.client_id = c.id
WHERE par.type = 'real' AND par.dispatch_date BETWEEN '2026-03-01' AND '2026-03-31'
  AND par.deleted_at IS NULL AND pop.muestra = 0
ORDER BY par.dispatch_date DESC;

OC con despacho parcial — qué queda pendiente para Alexa (parcial_status):
-- Muestra kilos pedidos, kilos ya despachados y kilos pendientes por OC
SELECT po.order_consecutive, c.client_name, po.order_creation_date,
  SUM(pop.quantity) kilos_pedidos,
  COALESCE(SUM(par.quantity), 0) kilos_despachados,
  SUM(pop.quantity) - COALESCE(SUM(par.quantity), 0) kilos_pendientes,
  SUM(pop.quantity * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_total_usd,
  SUM(COALESCE(par.quantity,0) * COALESCE(NULLIF(pop.price,0), p.price, 0)) valor_despachado_usd
FROM purchase_orders po
JOIN clients c ON po.client_id = c.id
JOIN purchase_order_product pop ON pop.purchase_order_id = po.id
JOIN products p ON pop.product_id = p.id
LEFT JOIN partials par ON par.order_id = po.id AND par.product_order_id = pop.id
  AND par.type = 'real' AND par.deleted_at IS NULL
WHERE po.status = 'parcial_status' AND pop.muestra = 0
GROUP BY po.id, c.client_name, po.order_creation_date
HAVING kilos_pendientes > 0
ORDER BY po.order_creation_date;
SCHEMA;
    }
}
