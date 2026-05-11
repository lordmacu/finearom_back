<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesHistoryController extends Controller
{
    // Mapa de mes en español → número (para ordenar)
    private const MES_ORDEN = [
        'ENERO'      => 1,
        'FEBRERO'    => 2,
        'MARZO'      => 3,
        'ABRIL'      => 4,
        'MAYO'       => 5,
        'JUNIO'      => 6,
        'JULIO'      => 7,
        'AGOSTO'     => 8,
        'SEPTIEMBRE' => 9,
        'OCTUBRE'    => 10,
        'NOVIEMBRE'  => 11,
        'DICIEMBRE'  => 12,
    ];

    /**
     * POST /sales-history/import
     * Recibe el archivo Excel, trunca la tabla y re-inserta todo.
     */
    public function import(Request $request): JsonResponse
    {
        Log::info('[SalesHistory] Import iniciado', [
            'user'      => auth()->id(),
            'file_name' => $request->file('file')?->getClientOriginalName(),
            'file_size' => $request->file('file')?->getSize(),
        ]);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:51200'], // 50 MB
        ]);

        // Guardar el archivo en /tmp con nombre único
        $tmpPath = $request->file('file')->storeAs('', uniqid('sales_') . '.xlsx', 'local');
        $fullPath = storage_path('app/' . $tmpPath);

        Log::info('[SalesHistory] Archivo guardado', ['path' => $fullPath]);

        $scriptPath = base_path('scripts/import_sales_history.py');
        $python     = $this->resolvePython();

        Log::info('[SalesHistory] Ejecutando Python', ['python' => $python, 'script' => $scriptPath]);

        $command    = escapeshellcmd("$python $scriptPath " . escapeshellarg($fullPath)) . ' 2>&1';
        $output     = shell_exec($command);

        // Limpiar archivo temporal
        @unlink($fullPath);

        Log::info('[SalesHistory] Salida Python', ['output' => $output]);

        if (!$output || !str_contains($output, 'OK:')) {
            Log::error('[SalesHistory] Error en script Python', ['output' => $output]);
            return response()->json(['message' => 'Error al importar: ' . trim($output ?? 'Sin respuesta del script')], 500);
        }

        // Extraer el total de la última línea "OK:79541"
        preg_match('/OK:(\d+)/', $output, $matches);
        $total = (int) ($matches[1] ?? 0);

        Log::info('[SalesHistory] Import finalizado', ['total_en_tabla' => $total]);

        return response()->json([
            'message' => 'Importación completada exitosamente',
            'total'   => $total,
        ]);
    }

    /**
     * Encuentra el binario de Python disponible en el sistema.
     */
    private function resolvePython(): string
    {
        foreach (['python3', 'python'] as $bin) {
            $path = trim(shell_exec("which $bin 2>/dev/null") ?? '');
            if ($path) {
                return $path;
            }
        }
        return 'python3';
    }

    /**
     * GET /sales-history/clients
     * Retorna la lista de clientes únicos: los que tienen historial de ventas
     * O pronósticos guardados (sales_forecasts). Así un cliente al que solo se
     * le cargaron pronósticos manuales también aparece en el selector.
     */
    public function clients(): JsonResponse
    {
        $clients = DB::select("
            SELECT c.nit,
                   c.client_name AS cliente,
                   c.client_type AS cliente_tipo
            FROM clients c
            WHERE c.nit IN (
                SELECT nit FROM sales_history
                UNION
                SELECT nit FROM sales_forecasts
            )
            GROUP BY c.nit, c.client_name, c.client_type
            ORDER BY c.client_name
        ");

        return response()->json(['data' => $clients]);
    }

    /**
     * GET /sales-history/products?nit=xxx
     * Retorna los productos únicos de un cliente: los que tienen historial
     * O pronósticos guardados para ese NIT. Si el código no existe en la
     * tabla products, igual se devuelve usando el código como referencia.
     */
    public function products(Request $request): JsonResponse
    {
        $request->validate(['nit' => ['required', 'string']]);

        $nit = $request->nit;

        $products = DB::select("
            SELECT codes.codigo,
                   COALESCE(p.product_name, codes.codigo) AS referencia,
                   p.categories AS categoria
            FROM (
                SELECT codigo FROM sales_history WHERE nit = ?
                UNION
                SELECT codigo FROM sales_forecasts WHERE nit = ?
            ) codes
            LEFT JOIN products p
                ON p.code COLLATE utf8mb4_unicode_ci = codes.codigo
            GROUP BY codes.codigo, p.product_name, p.categories
            ORDER BY referencia
        ", [$nit, $nit]);

        return response()->json(['data' => $products]);
    }

    /**
     * GET /sales-history/chart?nit=xxx&codigo=yyy
     * Retorna los datos mensuales para la gráfica de barras.
     * Ordenados cronológicamente (año ASC, mes ASC por número de mes).
     */
    public function chart(Request $request): JsonResponse
    {
        $request->validate([
            'nit'    => ['required', 'string'],
            'codigo' => ['required', 'string'],
        ]);

        $rows = DB::table('sales_history')
            ->select('año', 'mes', DB::raw('SUM(venta) as venta'), DB::raw('SUM(cantidad) as cantidad'))
            ->where('nit', $request->nit)
            ->where('codigo', $request->codigo)
            ->groupBy('año', 'mes')
            ->get();

        // Ordenar: año ASC → mes por número ASC
        $mesOrden = self::MES_ORDEN;
        $rows = $rows->sortBy(function ($row) use ($mesOrden) {
            $numMes = $mesOrden[strtoupper($row->mes)] ?? 99;
            return $row->año . sprintf('%02d', $numMes);
        })->values();

        // Etiquetas abreviadas para el eje X
        $mesAbrev = [
            'ENERO' => 'Ene', 'FEBRERO' => 'Feb', 'MARZO' => 'Mar',
            'ABRIL' => 'Abr', 'MAYO' => 'May', 'JUNIO' => 'Jun',
            'JULIO' => 'Jul', 'AGOSTO' => 'Ago', 'SEPTIEMBRE' => 'Sep',
            'OCTUBRE' => 'Oct', 'NOVIEMBRE' => 'Nov', 'DICIEMBRE' => 'Dic',
        ];

        $labels    = [];
        $ventas    = [];
        $cantidades = [];

        foreach ($rows as $row) {
            $abrev      = $mesAbrev[strtoupper($row->mes)] ?? $row->mes;
            $labels[]   = $abrev . ' ' . $row->año;
            $ventas[]   = (int) $row->venta;
            $cantidades[] = (int) $row->cantidad;
        }

        // Info del producto/cliente directamente desde clients/products
        // (no depende de que exista historial de ventas).
        $cliente = DB::table('clients')->where('nit', $request->nit)->value('client_name');
        $producto = DB::table('products')
            ->where(DB::raw('code COLLATE utf8mb4_unicode_ci'), $request->codigo)
            ->first(['product_name', 'categories']);

        $info = (object) [
            'cliente'    => $cliente,
            'referencia' => $producto->product_name ?? $request->codigo,
            'categoria'  => $producto->categories ?? null,
        ];

        return response()->json([
            'data' => [
                'labels'     => $labels,
                'ventas'     => $ventas,
                'cantidades' => $cantidades,
                'info'       => $info,
            ],
        ]);
    }

    /**
     * GET /sales-history/forecast?nit=xxx&codigo=yyy[&modelo=holt_winters]
     * Retorna los pronósticos guardados para un cliente+producto.
     */
    public function forecast(Request $request): JsonResponse
    {
        $request->validate([
            'nit'    => ['required', 'string'],
            'codigo' => ['required', 'string'],
            'modelo' => ['nullable', 'string'],
        ]);

        $query = DB::table('sales_forecasts')
            ->where('nit', $request->nit)
            ->where('codigo', $request->codigo);

        if ($request->filled('modelo')) {
            $query->where('modelo', $request->modelo);
        }

        $rows = $query->orderByRaw("año ASC, FIELD(mes,'ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO','JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE') ASC")
            ->get();

        // Agrupar por modelo → array de 4 meses
        $byModel = [];
        foreach ($rows as $row) {
            $byModel[$row->modelo][] = [
                'año'               => $row->año,
                'mes'               => $row->mes,
                'cantidad_forecast' => (int) $row->cantidad_forecast,
                'lower_bound'       => (int) $row->lower_bound,
                'upper_bound'       => (int) $row->upper_bound,
                'confianza'         => $row->confianza,
            ];
        }

        $generatedAt = DB::table('sales_forecasts')
            ->where('nit', $request->nit)
            ->where('codigo', $request->codigo)
            ->value('generated_at');

        return response()->json([
            'data' => [
                'modelos'      => $byModel,
                'generated_at' => $generatedAt,
            ],
        ]);
    }

    /**
     * POST /sales-history/manual-forecast
     * Guarda o actualiza pronósticos manuales para un cliente+producto.
     */
    public function saveManualForecast(Request $request): JsonResponse
    {
        $request->validate([
            'nit'            => ['required', 'string'],
            'codigo'         => ['required', 'string'],
            'forecasts'      => ['required', 'array', 'min:1'],
            'forecasts.*.año' => ['required', 'string'],
            'forecasts.*.mes' => ['required', 'string'],
            'forecasts.*.cantidad' => ['required', 'integer', 'min:0'],
        ]);

        $nit    = $request->nit;
        $codigo = $request->codigo;
        $now    = now()->toDateTimeString();

        foreach ($request->forecasts as $f) {
            DB::table('sales_forecasts')->updateOrInsert(
                [
                    'nit'    => $nit,
                    'codigo' => $codigo,
                    'modelo' => 'manual',
                    'año'    => $f['año'],
                    'mes'    => strtoupper($f['mes']),
                ],
                [
                    'cantidad_forecast' => (int) $f['cantidad'],
                    'lower_bound'       => null,
                    'upper_bound'       => null,
                    'confianza'         => 'alta',
                    'generated_at'      => $now,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Pronóstico manual guardado',
        ]);
    }

    /**
     * GET /sales-history/status
     * Indica si hay datos importados y cuántos registros hay.
     */
    public function status(): JsonResponse
    {
        $count = DB::table('sales_history')->count();

        return response()->json([
            'data' => [
                'has_data' => $count > 0,
                'total'    => $count,
            ],
        ]);
    }
}
