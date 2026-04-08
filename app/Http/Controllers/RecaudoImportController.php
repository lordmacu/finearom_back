<?php

namespace App\Http\Controllers;

use App\Models\Recaudo;
use App\Queries\Cartera\CarteraQuery;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecaudoImportController extends Controller
{
    public function __construct(
        private readonly CarteraQuery $carteraQuery
    ) {
    }
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
                Recaudo::truncate();

                $rows = [];
                for ($row = $dataStartRow; $row <= $highestRow; $row++) {
                    $rowData = $this->extractRowData($worksheet, $row);

                    if (!$this->isValidRow($rowData)) {
                        continue;
                    }

                    $rows[] = [
                        'fecha_recaudo'    => $rowData['fecha_recaudo'],
                        'numero_recibo'    => $rowData['numero_recibo'],
                        'fecha_vencimiento'=> $rowData['fecha_vencimiento'],
                        'numero_factura'   => $rowData['numero_factura'],
                        'nit'              => $rowData['nit'],
                        'cliente'          => $rowData['cliente'],
                        'dias'             => $rowData['dias'],
                        'valor_cancelado'  => $rowData['valor_cancelado'],
                        'observaciones'    => $rowData['observaciones'] ?? null,
                    ];
                    $imported++;
                }

                foreach (array_chunk($rows, 500) as $chunk) {
                    Recaudo::insert($chunk);
                }

                DB::commit();

                // Limpiar caché de customers después de importar recaudos
                $this->carteraQuery->clearCustomersCache();

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
        $recaudos = Recaudo::query()
            ->orderBy('fecha_recaudo', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $recaudos,
        ]);
    }

    /**
     * Descarga un archivo Excel de plantilla con los encabezados correctos.
     */
    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Recaudos');

        // Filas 1-6: título informativo
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', 'PLANTILLA DE IMPORTACIÓN DE RECAUDOS');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        $sheet->mergeCells('A2:H2');
        $sheet->setCellValue('A2', 'Los encabezados deben estar en la fila 7. Los datos comienzan en la fila 8.');
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['italic' => true, 'color' => ['rgb' => '555555']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Fila 7: encabezados
        $headers = [
            'A7' => 'FEC.REC.',
            'B7' => 'NUMERO DEL RECIBO',
            'C7' => 'FEC.VTO.',
            'D7' => 'NUMERO FACTURA',
            'E7' => 'NIT',
            'F7' => 'CLIENTE',
            'G7' => 'DIAS',
            'H7' => 'VALOR CANCELADO',
        ];

        foreach ($headers as $cell => $label) {
            $sheet->setCellValue($cell, $label);
        }

        $sheet->getStyle('A7:H7')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2345']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // Fila 8: ejemplo
        $sheet->setCellValue('A8', '15/04/2026');
        $sheet->setCellValue('B8', 'REC-001');
        $sheet->setCellValue('C8', '30/04/2026');
        $sheet->setCellValue('D8', 'FAC-12345');
        $sheet->setCellValue('E8', '900123456');
        $sheet->setCellValue('F8', 'CLIENTE EJEMPLO S.A.S');
        $sheet->setCellValue('G8', 0);
        $sheet->setCellValue('H8', 1500000);

        $sheet->getStyle('A8:H8')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F4FF']],
            'font' => ['italic' => true, 'color' => ['rgb' => '888888']],
        ]);

        // Anchos de columna
        foreach (['A' => 14, 'B' => 22, 'C' => 14, 'D' => 20, 'E' => 15, 'F' => 30, 'G' => 8, 'H' => 18] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'plantilla_recaudos.xlsx', [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla_recaudos.xlsx"',
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
