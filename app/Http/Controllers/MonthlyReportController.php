<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function chatStart(): JsonResponse
    {
        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        if (!Storage::disk('local')->exists('monthly_report.json')) {
            return response()->json([
                'success' => false,
                'message' => 'No hay reporte generado. Presiona "Generar reporte IA" primero.',
            ], 404);
        }

        $reportJson = Storage::disk('local')->get('monthly_report.json');
        $report     = json_decode($reportJson, true);
        $period     = $report['period'] ?? [];
        $now        = Carbon::now('America/Bogota');
        $today      = $now->toDateString();

        // TRM de hoy (o la más reciente disponible) — para conversiones de cartera
        $trmHoy = DB::table('trm_daily')
            ->where('date', '<=', $today)
            ->orderByDesc('date')
            ->value('value');
        $trmHoyStr = $trmHoy ? number_format((float)$trmHoy, 2) : '4000.00';

        $prompt = "Eres un asistente de análisis comercial para Finearom. " .
                  "Te comparto el reporte mensual del período {$period['start_date']} al {$period['end_date']}. " .
                  "Responde todas las preguntas de forma clara y concisa en español. " .
                  "Cuando el usuario haga una pregunta, respóndela directamente sin repetir el contexto.\n" .
                  "Fecha y hora actual (Colombia): {$now->format('Y-m-d H:i:s')} — úsala como referencia para calcular rangos relativos (hoy, esta semana, hace N días, etc.).\n" .
                  "TRM de hoy ({$today}): \${$trmHoyStr} COP/USD — úsala SOLO para convertir valores de cartera a USD si te lo piden. Las órdenes tienen su propia TRM individual y no deben usar esta.\n\n" .

                  "NOTAS CLAVE — lee antes de responder:\n" .
                  "- 'Total órdenes creadas' = valor de TODAS las OC del período, sin importar si se despacharon o no\n" .
                  "- 'Despachado / Facturado' = dinero ya enviado al cliente. 'Pendiente' = Total − Facturado\n" .
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
                  "- Cumplimiento del período = (total despachado USD) / (total órdenes creadas USD) × 100. Si se pregunta cuánto sería el cumplimiento si todo lo planeado se despacha, sumar el planeado + lo ya despachado y dividir entre el total creado\n\n" .

                  "REPORTE:\n" . ($md = $this->buildReportMarkdown($report)) . "\n\n" .
                  "Confirma que recibiste el reporte con un mensaje breve de bienvenida (2-3 líneas) " .
                  "indicando el período, el total de órdenes creadas (valor USD), y cuánto se despachó/facturó realmente en USD.";

        Storage::disk('local')->put('monthly_report.md', $md);

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

            return response()->json([
                'success'   => true,
                'thread_id' => $resp->json('thread_id'),
                'message'   => trim($resp->json('choices.0.message.content', 'Listo, puedes hacerme preguntas sobre el reporte.')),
                'period'    => $period,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Chat] chatStart exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Envía un mensaje del usuario al hilo existente y devuelve la respuesta de la IA.
     */
    public function chatMessage(Request $request): JsonResponse
    {
        $request->validate([
            'thread_id' => 'required|string',
            'message'   => 'required|string|max:2000',
        ]);

        $aiUrl = config('custom.ai_server_url');
        $aiKey = config('custom.ai_server_key');

        try {
            $resp = Http::withHeaders(['X-Api-Key' => $aiKey])
                ->timeout(120)
                ->post("{$aiUrl}/v1/chat/completions", [
                    'model'     => 'gpt-4.1',
                    'messages'  => [['role' => 'user', 'content' => $request->message]],
                    'thread_id' => $request->thread_id,
                ]);

            if (!$resp->successful()) {
                Log::error('[Chat] Error en chatMessage: ' . $resp->body());
                return response()->json(['success' => false, 'message' => 'Error en IA'], 500);
            }

            return response()->json([
                'success'   => true,
                'message'   => trim($resp->json('choices.0.message.content', '')),
                'thread_id' => $resp->json('thread_id'),
            ]);
        } catch (\Throwable $e) {
            Log::error('[Chat] chatMessage exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Generar y guardar reporte en disco
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Genera el reporte mensual y lo guarda en storage/app/monthly_report.json
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
                'success'    => true,
                'generated_at' => now()->toIso8601String(),
                'period'     => ['start_date' => $startDate, 'end_date' => $endDate],
                'ordenes_mes' => $this->buildOrdenes($startDate, $endDate),
                'stats'      => $this->buildStats($startDate, $endDate),
            ];

            Storage::disk('local')->put('monthly_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            Log::info("[MonthlyReport] Reporte generado y guardado ({$startDate} → {$endDate})");

            return response()->json([
                'success'      => true,
                'message'      => 'Reporte generado correctamente',
                'generated_at' => $report['generated_at'],
                'period'       => $report['period'],
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
        // Pre-computed daily snapshots
        $dailyStats = DB::table('order_statistics')
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->get();

        // Totals from pre-computed stats
        $totalUsd      = $dailyStats->sum('commercial_dispatched_value_usd');
        $totalCop      = $dailyStats->sum('commercial_dispatched_value_cop');
        $totalProducts = $dailyStats->sum('commercial_products_dispatched');
        $totalOrders   = $dailyStats->sum('dispatched_orders_count');
        $daysCount     = $dailyStats->count();

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
                if ($realPartial && !empty($partialTrm) && $partialTrm > 3800) {
                    $trm = (float) $partialTrm;
                } elseif (isset($trmData[$plannedDate])) {
                    $trm = (float) $trmData[$plannedDate];
                } elseif (!empty($first->order_trm) && $first->order_trm > 3800) {
                    $trm = (float) $first->order_trm;
                }

                $plannedUsd += $price * $qty;
                $plannedCop += $price * $qty * $trm;
            }

            $ordersSet[$orderId] = true;
        }
        $plannedOrdersCount = count($ordersSet);

        // Orders created in the period — total y valores queried directamente sobre purchase_orders
        // (los snapshots de order_statistics pueden estar incompletos para el mes en curso)
        $ordersCreatedQuery = DB::table('purchase_orders as po')
            ->join('purchase_order_product as pop', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'po.order_creation_date', '=', 'td.date')
            ->whereBetween('po.order_creation_date', [$startDate, $endDate])
            ->where('pop.muestra', '=', 0)
            ->selectRaw("
                COUNT(DISTINCT po.id) as total_orders,
                SUM(CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END * pop.quantity) as value_usd,
                SUM(
                    (CASE WHEN pop.price > 0 THEN pop.price ELSE p.price END) * pop.quantity *
                    COALESCE(NULLIF(po.trm, 0), NULLIF(td.value, 0), 4000)
                ) as value_cop
            ")
            ->first();

        $totalOrdersCreated  = (int)   ($ordersCreatedQuery->total_orders ?? $dailyStats->sum('total_orders_created'));
        $ordersValueUsd      = (float) ($ordersCreatedQuery->value_usd    ?? $dailyStats->sum('total_orders_value_usd'));
        $ordersValueCop      = (float) ($ordersCreatedQuery->value_cop    ?? $dailyStats->sum('total_orders_value_cop'));

        // Status counts — queried directly from purchase_orders for accuracy
        $statusCounts = DB::table('purchase_orders')
            ->whereBetween('order_creation_date', [$startDate, $endDate])
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
            ->sum(DB::raw("CAST(REPLACE(REPLACE(REPLACE(saldo_contable, ' ', ''), '.', ''), ',', '.') AS DECIMAL(20,2))"));

        // Deuda neta (saldo - recaudos totales por factura, mínimo 0)
        $netDebt = (float) DB::table('cartera as car')
            ->leftJoin('recaudos as r', 'r.numero_factura', '=', 'car.documento')
            ->where('car.fecha_cartera', '=', $latestCarteraDate)
            ->groupBy('car.documento', 'car.saldo_contable')
            ->selectRaw("GREATEST(CAST(REPLACE(REPLACE(REPLACE(car.saldo_contable, ' ', ''), '.', ''), ',', '.') AS DECIMAL(20,2)) - COALESCE(SUM(r.valor_cancelado), 0), 0) as net_debt")
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
            ->selectRaw("GREATEST(CAST(REPLACE(REPLACE(REPLACE(car.saldo_contable, ' ', ''), '.', ''), ',', '.') AS DECIMAL(20,2)) - COALESCE(SUM(r.valor_cancelado), 0), 0) as net_debt")
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

        $ordersQuery = DB::table('purchase_orders as po')
            ->join('clients as c', 'po.client_id', '=', 'c.id')
            ->join('purchase_order_product as pop', 'pop.purchase_order_id', '=', 'po.id')
            ->join('products as p', 'pop.product_id', '=', 'p.id')
            ->leftJoin('trm_daily as td', 'po.order_creation_date', '=', 'td.date')
            ->whereBetween('po.order_creation_date', [$startDate, $endDate])
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
                $dispatchMerged[$exec] = ['dispatched_orders' => 0, 'dispatched_kilos' => 0.0, 'dispatched_cop' => 0.0];
            }
            $dispatchMerged[$exec]['dispatched_orders'] += (int)   $row->dispatched_orders;
            $dispatchMerged[$exec]['dispatched_kilos']  += (float) $row->dispatched_kilos;
            $dispatchMerged[$exec]['dispatched_cop']    += (float) $row->dispatched_cop;
        }

        $totalValueCop = array_sum(array_column($merged, 'value_cop'));

        $result = [];
        foreach ($merged as $exec => $row) {
            $dispatch  = $dispatchMerged[$exec] ?? null;
            $valueCop  = $row['value_cop'];
            $kilos     = $row['total_kilos'];
            $dispCop    = $dispatch ? $dispatch['dispatched_cop']    : 0;
            $dispKilos  = $dispatch ? $dispatch['dispatched_kilos']  : 0;
            $dispOrders = $dispatch ? $dispatch['dispatched_orders'] : 0;

            $result[] = [
                'executive'            => $exec,
                'total_orders'         => $row['total_orders'],
                'value_usd'            => round($row['value_usd'], 2),
                'value_cop'            => round($valueCop, 0),
                'total_kilos'          => round($kilos, 2),
                'dispatched_orders'    => $dispOrders,
                'dispatched_cop'       => round($dispCop, 0),
                'dispatched_kilos'     => round($dispKilos, 2),
                'participation_pct'    => $totalValueCop > 0 ? round($valueCop / $totalValueCop * 100, 1) : 0,
                'compliance_cop_pct'   => $valueCop > 0 ? round($dispCop   / $valueCop * 100, 1) : 0,
                'compliance_kilos_pct' => $kilos    > 0 ? round($dispKilos / $kilos    * 100, 1) : 0,
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
        $md .= "| Total órdenes creadas | \${$n($oc['value_usd'] ?? 0, 2)} | \${$n($oc['value_cop'] ?? 0)} |\n";
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
        $md .= "## TRM\n";
        $md .= "- **TRM promedio del período:** \${$n($trm['average'] ?? 0, 2)} COP/USD\n";
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
        $md .= "| Ejecutiva | OC | Valor USD | Valor COP | $/kg USD | Kilos | Part.% | OC desp. | Desp. COP | Desp. kg | Cump.% |\n";
        $md .= "|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|\n";
        foreach ($execs as $e) {
            $kilos    = (float) $e['total_kilos'];
            $valueUsd = (float) $e['value_usd'];
            $pxkg     = $kilos > 0 ? $n($valueUsd / $kilos, 2) : '—';
            $md .= "| {$e['executive']}";
            $md .= " | {$e['total_orders']}";
            $md .= " | \${$n($valueUsd, 2)}";
            $md .= " | \${$n($e['value_cop'])}";
            $md .= " | \${$pxkg}";
            $md .= " | {$n($kilos, 2)}";
            $md .= " | {$e['participation_pct']}%";
            $md .= " | {$e['dispatched_orders']}";
            $md .= " | \${$n($e['dispatched_cop'])}";
            $md .= " | {$n($e['dispatched_kilos'], 2)}";
            $md .= " | {$e['compliance_cop_pct']}%";
            $md .= " |\n";
        }
        $md .= "\n";

        // ── DETALLE DE ÓRDENES DEL MES ──────────────────────────────────────
        $md .= "## DETALLE DE ÓRDENES DEL MES\n\n";
        foreach ($orders as $o) {
            $cons    = $o['consecutivo']    ?? '';
            $estado  = $o['estado']         ?? '';
            $cliente = $o['cliente']        ?? '';
            $exec    = $o['ejecutivo']      ?? '';
            $ciudad  = $o['ciudad_entrega'] ?? '';
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

            $md .= "### OC {$cons}{$tagStr} — {$estado} | Ejecutiva: {$exec} | {$n($totalKgOc, 2)} kg\n";
            $md .= "- **Cliente:** {$cliente} | **Ciudad:** {$ciudad}\n";
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
}
