<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:51200'], // 50 MB
        ]);

        set_time_limit(300);

        $path = $request->file('file')->getRealPath();

        try {
            // ReadDataOnly=true: omite estilos, formatos y fórmulas → 3-5x más rápido
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
            $sheet       = $spreadsheet->getActiveSheet();

            // false, false, false → sin calcular fórmulas, sin formatear, sin cabeceras de columna
            $rows = $sheet->toArray(null, false, false, false);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error al leer el archivo: ' . $e->getMessage()], 422);
        }

        // Quitar la fila de encabezado
        array_shift($rows);

        if (empty($rows)) {
            return response()->json(['message' => 'El archivo no contiene datos'], 422);
        }

        // Liberar memoria del spreadsheet antes de procesar
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        // Truncar y re-insertar dentro de una transacción
        // Deshabilitar query log para evitar acumulación en memoria
        DB::disableQueryLog();
        DB::table('sales_history')->truncate();

        $batch = [];
        $now   = now()->toDateTimeString();
        $chunk = 2000;

        DB::transaction(function () use ($rows, $now, $chunk, &$batch) {
            foreach ($rows as $row) {
                // Columnas del Excel (0-indexed):
                // 0=NIT, 1=CODIGO BUSQUEDA, 2=CLIENTE, 3=EJECUTIVO, 4=CLIENTE2,
                // 5=CATEGORIA, 6=CODIGO, 7=REFERENCIA, 8=REF-CODIGO,
                // 9=AÑO, 10=MES, 11=VENTA, 12=CANTIDAD, 13=NEWWIN, 14=ESTADO

                $nit    = trim((string) ($row[0] ?? ''));
                $codigo = trim((string) ($row[6] ?? ''));
                $año    = trim((string) ($row[9] ?? ''));
                $mes    = strtoupper(trim((string) ($row[10] ?? '')));

                // Saltar filas sin datos mínimos
                if ($nit === '' || $codigo === '' || $año === '' || $mes === '') {
                    continue;
                }

                // Normalizar categoría (FINE FRAGANCE → FINE FRAGRANCE)
                $categoria = trim((string) ($row[5] ?? ''));
                if ($categoria === 'FINE FRAGANCE') {
                    $categoria = 'FINE FRAGRANCE';
                }

                $batch[] = [
                    'nit'          => $nit,
                    'cliente'      => trim((string) ($row[2] ?? '')),
                    'ejecutivo'    => trim((string) ($row[3] ?? '')) ?: null,
                    'cliente_tipo' => trim((string) ($row[4] ?? '')) ?: null,
                    'categoria'    => $categoria ?: null,
                    'codigo'       => $codigo,
                    'referencia'   => trim((string) ($row[7] ?? '')),
                    'año'          => $año,
                    'mes'          => $mes,
                    'venta'        => (int) ($row[11] ?? 0),
                    'cantidad'     => (int) ($row[12] ?? 0),
                    'newwin'       => ($row[13] === 'NEW WIN'),
                    'estado'       => trim((string) ($row[14] ?? '')) ?: null,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ];

                if (count($batch) >= $chunk) {
                    DB::table('sales_history')->insert($batch);
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                DB::table('sales_history')->insert($batch);
            }
        });

        $total = DB::table('sales_history')->count();

        return response()->json([
            'message' => 'Importación completada exitosamente',
            'total'   => $total,
        ]);
    }

    /**
     * GET /sales-history/clients
     * Retorna la lista de clientes únicos.
     */
    public function clients(): JsonResponse
    {
        $clients = DB::table('sales_history')
            ->select('nit', 'cliente', 'cliente_tipo')
            ->groupBy('nit', 'cliente', 'cliente_tipo')
            ->orderBy('cliente')
            ->get();

        return response()->json(['data' => $clients]);
    }

    /**
     * GET /sales-history/products?nit=xxx
     * Retorna los productos únicos de un cliente.
     */
    public function products(Request $request): JsonResponse
    {
        $request->validate(['nit' => ['required', 'string']]);

        $products = DB::table('sales_history')
            ->select('codigo', 'referencia', 'categoria')
            ->where('nit', $request->nit)
            ->groupBy('codigo', 'referencia', 'categoria')
            ->orderBy('referencia')
            ->get();

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

        // Info del producto/cliente
        $info = DB::table('sales_history')
            ->select('cliente', 'referencia', 'categoria')
            ->where('nit', $request->nit)
            ->where('codigo', $request->codigo)
            ->first();

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
