<?php

namespace App\Http\Controllers;

use App\Models\Recaudo;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class RecaudoImportController extends Controller
{
    /**
     * Importa recaudos desde Excel/CSV.
     * Encabezados esperados (case-insensitive):
     * fecha_recaudo, numero_recibo, fecha_vencimiento, numero_factura, nit, cliente, dias, valor_cancelado, observaciones.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx,csv,txt'],
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            return response()->json(['success' => false, 'message' => 'Archivo sin datos'], 422);
        }

        $headers = array_map(fn($val) => strtolower(trim((string) $val)), $rows[1] ?? []);
        $colIndex = array_flip($headers);
        $required = ['fecha_recaudo', 'numero_recibo', 'fecha_vencimiento', 'numero_factura', 'nit', 'cliente', 'dias', 'valor_cancelado', 'observaciones'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $colIndex)) {
                return response()->json(['success' => false, 'message' => "Falta columna requerida: {$key}"], 422);
            }
        }

        $inserted = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            for ($i = 2; $i <= count($rows); $i++) {
                $row = $rows[$i] ?? [];
                if (! is_array($row)) {
                    continue;
                }

                $fechaRecaudo = $this->parseDate($row[$colIndex['fecha_recaudo'] + 1] ?? null);
                $fechaVencimiento = $this->parseDate($row[$colIndex['fecha_vencimiento'] + 1] ?? null);
                $numeroRecibo = trim((string) ($row[$colIndex['numero_recibo'] + 1] ?? ''));
                $numeroFactura = trim((string) ($row[$colIndex['numero_factura'] + 1] ?? ''));
                $nit = trim((string) ($row[$colIndex['nit'] + 1] ?? ''));
                $cliente = trim((string) ($row[$colIndex['cliente'] + 1] ?? ''));
                $dias = $row[$colIndex['dias'] + 1] ?? null;
                $valorCancelado = $row[$colIndex['valor_cancelado'] + 1] ?? null;
                $observaciones = trim((string) ($row[$colIndex['observaciones'] + 1] ?? ''));

                if ($numeroRecibo === '' || $numeroFactura === '' || $valorCancelado === null) {
                    $errors[] = "Fila {$i}: datos requeridos vacíos";
                    continue;
                }

                $diasInt = is_numeric($dias) ? (int) $dias : null;
                $valor = is_numeric($valorCancelado) ? (string) $valorCancelado : null;

                if ($valor === null) {
                    $errors[] = "Fila {$i}: valor_cancelado no numérico";
                    continue;
                }

                Recaudo::query()->updateOrCreate(
                    [
                        'numero_recibo' => $numeroRecibo,
                        'numero_factura' => $numeroFactura,
                    ],
                    [
                        'fecha_recaudo' => $fechaRecaudo,
                        'fecha_vencimiento' => $fechaVencimiento,
                        'nit' => $nit,
                        'cliente' => $cliente,
                        'dias' => $diasInt,
                        'valor_cancelado' => $valor,
                        'observaciones' => $observaciones,
                    ]
                );
                $inserted++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al importar: ' . $e->getMessage()], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Recaudos importados',
            'inserted_or_updated' => $inserted,
            'errors' => $errors,
        ]);
    }

    private function parseDate($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
