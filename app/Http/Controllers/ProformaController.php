<?php

namespace App\Http\Controllers;

use App\Http\Requests\Proforma\ProformaUploadRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProformaController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:proforma upload')->only(['upload']);
    }

    /**
     * Procesa archivo Excel de proforma y actualiza datos tributarios de clientes.
     *
     * Legacy equivalente: POST /admin/proforma/upload
     */
    public function upload(ProformaUploadRequest $request): JsonResponse
    {
        $file = $request->file('file');

        try {
            // Leer el archivo Excel
            $spreadsheet = IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            $processedData = [];
            $updatedCount = 0;
            $notFoundNits = [];

            // Construir mapa de nombre de columna → índice desde la fila 1 (headers)
            $colMap = [];
            foreach ($rows[0] as $idx => $header) {
                if ($header !== null) {
                    $colMap[strtoupper(trim((string) $header))] = $idx;
                }
            }

            // Columnas esperadas (mayúsculas para comparación)
            $COL_NIT              = $colMap['NIT'] ?? 0;
            $COL_CLIENTE          = $colMap['CLIENTE'] ?? 1;
            $COL_VENTA_CONTADO    = $colMap['VENTA DE CONTADO'] ?? 2;
            $COL_CIUDAD           = $colMap['CIUDAD'] ?? 3;
            $COL_TIPO_CONTRIB     = $colMap['TIPO CONTRIBUYENTE'] ?? 4;
            $COL_ZONA_FRANCA      = $colMap['ZONA FRANCA'] ?? 9;
            $COL_IVA              = $colMap['IVA'] ?? 10;
            $COL_RETEFUENTE       = $colMap['RETEFUENTE'] ?? 11;
            $COL_RETEIVA          = $colMap['RETE IVA'] ?? 12;
            $COL_ICA              = $colMap['ICA'] ?? 13;

            // Empezar desde la fila 2 (índice 1)
            for ($i = 1; $i < count($rows); $i++) {
                $row = $rows[$i];

                // Si el NIT está vacío, saltar
                if (empty($row[$COL_NIT])) {
                    continue;
                }

                $nit = (string) $row[$COL_NIT];
                $nombreCliente = (string) ($row[$COL_CLIENTE] ?? '');
                $ventaDeContado = (string) ($row[$COL_VENTA_CONTADO] ?? '');
                $ciudad = (string) ($row[$COL_CIUDAD] ?? '');
                $tipoContribuyente = (string) ($row[$COL_TIPO_CONTRIB] ?? '');
                $zonaFranca = (string) ($row[$COL_ZONA_FRANCA] ?? '');
                $ivaStr = (string) ($row[$COL_IVA] ?? '');
                $retefuenteStr = (string) ($row[$COL_RETEFUENTE] ?? '');
                $reteivaStr = (string) ($row[$COL_RETEIVA] ?? '');
                $icaStr = (string) ($row[$COL_ICA] ?? '');

                // Parsear valores numéricos
                $iva = $this->parseIva($ivaStr);
                $retefuente = $this->parseReteFuente($retefuenteStr);
                $reteiva = $this->parseReteIva($reteivaStr);
                $ica = $this->parseIca($icaStr);

                $client = Client::where('nit', $nit)->first();

                $processedData[] = [
                    'id' => $client?->id,
                    'nit' => $nit,
                    'nombre_cliente' => $nombreCliente,
                    'venta_de_contado' => $ventaDeContado,
                    'ciudad' => $ciudad,
                    'tipo_contribuyente' => $tipoContribuyente,
                    'zona_franca' => $zonaFranca,
                    'iva' => $iva,
                    'retefuente' => $retefuente,
                    'reteiva' => $reteiva,
                    'ica' => $ica,
                    'actualizado_proforma' => true,
                ];

                // Actualizar en base de datos
                $updatePayload = [
                    'venta_de_contado' => $ventaDeContado,
                    'ciudad' => $ciudad,
                    'tipo_contribuyente' => $tipoContribuyente,
                    'zona_franca' => ($zonaFranca === 'X' || strtoupper($zonaFranca) === 'X'),
                    'iva' => $iva,
                    'retefuente' => $retefuente,
                    'reteiva' => $reteiva,
                    'ica' => $ica,
                    'actualizado_proforma' => true,
                ];

                if ($nit === '900564535') {
                    \Log::info('PROFORMA DEBUG - NIT 900564535', [
                        'nit' => $nit,
                        'col_map' => $colMap,
                        'raw_row' => $row,
                        'parsed' => $updatePayload,
                        'client_exists' => Client::where('nit', $nit)->exists(),
                    ]);
                }

                if ($client) {
                    $client->update($updatePayload);
                    $updatedCount++;
                } else {
                    $notFoundNits[] = $nit;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Archivo procesado exitosamente. {$updatedCount} clientes actualizados.",
                'data' => $processedData,
                'meta' => [
                    'total_rows' => count($processedData),
                    'updated_count' => $updatedCount,
                    'not_found_nits' => $notFoundNits,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Descarga un archivo Excel de ejemplo con los headers requeridos y filas de muestra
     */
    public function downloadTemplate(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Headers
        $headers = [
            'A1' => 'NIT',
            'B1' => 'Cliente',
            'C1' => 'Venta de Contado',
            'D1' => 'Ciudad',
            'E1' => 'Tipo Contribuyente',
            'F1' => 'Zona Franca',
            'G1' => 'IVA',
            'H1' => 'RETEFUENTE',
            'I1' => 'RETE IVA',
            'J1' => 'ICA',
        ];

        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
            $sheet->getStyle($cell)->getFont()->setBold(true);
        }

        // Example rows
        $sheet->fromArray([
            ['900123456', 'Empresa Ejemplo S.A.S', 'SI', 'Bogotá', 'RESPONSABLE DE IVA', '', 'IVA:19', 'RETE FUENTE:2.5', 'RETE IVA:1.104', 'ICA:1.10'],
            ['800987654', 'Comercial Demo Ltda', 'NO', 'Medellín', 'NO RESPONSABLE', 'X', '19', '2.5', '1.104', ''],
        ], null, 'A2');

        // Auto-size columns
        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'proforma_ejemplo.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Extrae el valor de IVA del formato "IVA:19"
     */
    private function parseIva(string $iva): ?float
    {
        if (preg_match('/IVA:(\d+(\.\d+)?)/', $iva, $matches)) {
            return floatval($matches[1]);
        }
        // Si es un número directo
        if (is_numeric($iva)) {
            return floatval($iva);
        }
        return null;
    }

    /**
     * Extrae el valor de RETE FUENTE del formato "RETE FUENTE:2.5"
     */
    private function parseReteFuente(string $retefuente): ?float
    {
        if (preg_match('/RETE FUENTE:(\d+(\.\d+)?)/', $retefuente, $matches)) {
            return floatval($matches[1]);
        }
        if (is_numeric($retefuente)) {
            return floatval($retefuente);
        }
        return null;
    }

    /**
     * Extrae el valor de RETE IVA del formato "RETE IVA:1.104"
     */
    private function parseReteIva(string $reteiva): ?float
    {
        if (preg_match('/RETE IVA:(\d+(\.\d+)?)/', $reteiva, $matches)) {
            return floatval($matches[1]);
        }
        if (is_numeric($reteiva)) {
            return floatval($reteiva);
        }
        return null;
    }

    /**
     * Extrae el valor de ICA del formato "ICA:1.10"
     */
    private function parseIca(string $ica): ?float
    {
        if (preg_match('/ICA:(\d+(\.\d+)?)/', $ica, $matches)) {
            return floatval($matches[1]);
        }
        if (is_numeric($ica)) {
            return floatval($ica);
        }
        return null;
    }
}
