<?php

namespace App\Http\Controllers;

use App\Models\Recaudo;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class RecaudoImportController extends Controller
{
    /**
     * Importa recaudos desde Excel usando el formato estándar.
     * Encabezados en fila 7, datos desde fila 8.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:10240'],
        ]);

        try {
            $file = $request->file('file');
            $spreadsheet = IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getSheet(0);

            $highestRow = $worksheet->getHighestRow();
            $headerRow = $this->findHeaderRow($worksheet, $highestRow);

            if (!$headerRow) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato de archivo incorrecto. No se encontraron los encabezados esperados.'
                ], 422);
            }

            $imported = 0;
            $errors = [];
            $dataStartRow = $headerRow + 1;

            DB::beginTransaction();

            try {
                for ($row = $dataStartRow; $row <= $highestRow; $row++) {
                    $rowData = $this->extractRowData($worksheet, $row);

                    if (!$this->isValidRow($rowData)) {
                        continue;
                    }

                    try {
                        Recaudo::query()->updateOrCreate(
                            [
                                'numero_recibo' => $rowData['numero_recibo'],
                                'numero_factura' => $rowData['numero_factura'],
                            ],
                            [
                                'fecha_recaudo' => $rowData['fecha_recaudo'],
                                'fecha_vencimiento' => $rowData['fecha_vencimiento'],
                                'nit' => $rowData['nit'],
                                'cliente' => $rowData['cliente'],
                                'dias' => $rowData['dias'],
                                'valor_cancelado' => $rowData['valor_cancelado'],
                                'observaciones' => $rowData['observaciones'] ?? null,
                            ]
                        );

                        $imported++;
                    } catch (\Exception $e) {
                        $errors[] = "Error en fila {$row}: " . $e->getMessage();
                        Log::warning("Error importando fila {$row}: " . $e->getMessage());
                    }
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => "Se importaron {$imported} registros correctamente",
                    'inserted_or_updated' => $imported,
                    'total' => $highestRow - $headerRow,
                    'errors' => $errors,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Error importando recaudos: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al importar el archivo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener recaudos recientes
     */
    public function recent(Request $request): JsonResponse
    {
        $limit = $request->get('limit', 10);

        $recaudos = Recaudo::query()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $recaudos,
        ]);
    }

    /**
     * Buscar la fila de encabezados en el Excel
     */
    private function findHeaderRow($worksheet, $highestRow): ?int
    {
        $expectedHeaders = [
            'FEC.REC.',
            'NUMERO DEL RECIBO',
            'FEC.VTO.',
            'NUMERO FACTURA',
            'NIT',
            'CLIENTE',
            'DIAS',
            'VALOR CANCELADO'
        ];

        for ($row = 1; $row <= min(15, $highestRow); $row++) {
            $foundHeaders = 0;

            for ($col = 1; $col <= 8; $col++) {
                $cellValue = trim($worksheet->getCell([$col, $row])->getCalculatedValue() ?? '');

                foreach ($expectedHeaders as $expectedHeader) {
                    if (stripos($cellValue, trim($expectedHeader)) !== false) {
                        $foundHeaders++;
                        break;
                    }
                }
            }

            if ($foundHeaders >= 6) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Extraer datos de una fila específica
     */
    private function extractRowData($worksheet, $row): ?array
    {
        try {
            $fechaRecaudoRaw = $worksheet->getCell([1, $row])->getCalculatedValue();
            $numeroRecibo = trim($worksheet->getCell([2, $row])->getCalculatedValue() ?? '');
            $fechaVencimientoRaw = $worksheet->getCell([3, $row])->getCalculatedValue();
            $numeroFactura = trim($worksheet->getCell([4, $row])->getCalculatedValue() ?? '');
            $nit = $worksheet->getCell([5, $row])->getCalculatedValue();
            $cliente = trim($worksheet->getCell([6, $row])->getCalculatedValue() ?? '');
            $dias = $worksheet->getCell([7, $row])->getCalculatedValue();
            $valorCancelado = $worksheet->getCell([8, $row])->getCalculatedValue();

            $fechaRecaudo = $this->processExcelDate($fechaRecaudoRaw);
            $fechaVencimiento = $this->processExcelDate($fechaVencimientoRaw);

            $nit = is_numeric($nit) ? (string) ((int) $nit) : null;
            $dias = is_numeric($dias) ? (int) $dias : 0;
            $valorCancelado = is_numeric($valorCancelado) ? (float) $valorCancelado : 0;

            return [
                'fecha_recaudo' => $fechaRecaudo,
                'numero_recibo' => $numeroRecibo,
                'fecha_vencimiento' => $fechaVencimiento,
                'numero_factura' => $numeroFactura,
                'nit' => $nit,
                'cliente' => $cliente,
                'dias' => $dias,
                'valor_cancelado' => $valorCancelado,
                'observaciones' => null,
            ];

        } catch (\Exception $e) {
            Log::warning("Error procesando fila {$row}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Procesar fechas de Excel
     */
    private function processExcelDate($dateValue): ?string
    {
        if (empty($dateValue)) {
            return null;
        }

        try {
            if (is_numeric($dateValue)) {
                $date = ExcelDate::excelToDateTimeObject($dateValue);
                return $date->format('Y-m-d');
            }

            if (is_string($dateValue)) {
                $date = Carbon::parse($dateValue);
                return $date->format('Y-m-d');
            }

            if ($dateValue instanceof \DateTime) {
                return $dateValue->format('Y-m-d');
            }

            return null;

        } catch (\Exception $e) {
            Log::warning("Error procesando fecha: {$dateValue} - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Validar si una fila tiene datos válidos
     */
    private function isValidRow(?array $rowData): bool
    {
        if (!$rowData) {
            return false;
        }

        return !empty($rowData['numero_recibo']) &&
               !empty($rowData['numero_factura']) &&
               !empty($rowData['valor_cancelado']) &&
               $rowData['valor_cancelado'] > 0;
    }
}
