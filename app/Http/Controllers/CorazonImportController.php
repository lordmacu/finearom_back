<?php

namespace App\Http\Controllers;

use App\Http\Requests\Corazon\CorazonImportRequest;
use App\Services\CorazonImportService;
use Illuminate\Http\JsonResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CorazonImportController extends Controller
{
    public function __construct(
        private readonly CorazonImportService $service
    ) {
        $this->middleware('can:raw material create');
    }

    /** Valida (dry_run) o importa el Excel de corazones. */
    public function import(CorazonImportRequest $request): JsonResponse
    {
        $dryRun = $request->boolean('dry_run');
        $result = $this->service->procesar($request->file('file')->getRealPath(), $dryRun);

        if ($result['errors']) {
            return response()->json([
                'message' => 'El archivo tiene errores. No se importó nada.',
                'errors'  => $result['errors'],
            ], 422);
        }

        return response()->json([
            'data'    => $result['resumen'],
            'message' => $dryRun ? 'Archivo válido. Revisa el resumen y confirma la importación.' : 'Corazones importados correctamente.',
        ]);
    }

    /** Excel de ejemplo con el formato exacto que acepta el importador. */
    public function template(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Corazones');

        $sheet->fromArray(CorazonImportService::HEADERS, null, 'A1');
        $sheet->fromArray([
            ['EJEMPLO-001', 'CORAZON DE EJEMPLO', 'Pirámide olfativa del corazón (opcional)', '100000', 60],
            ['EJEMPLO-001', 'CORAZON DE EJEMPLO', 'Pirámide olfativa del corazón (opcional)', '100004', 40],
            ['EJEMPLO-002', 'OTRO CORAZON', '', '100000', 100],
        ], null, 'A2');

        $sheet->getStyle('A1:E1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F2345']],
        ]);
        foreach (['A' => 18, 'B' => 32, 'C' => 40, 'D' => 22, 'E' => 14] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle('A:A')->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('D:D')->getNumberFormat()->setFormatCode('@');
        $sheet->freezePane('A2');

        $ayuda = $spreadsheet->createSheet();
        $ayuda->setTitle('Instrucciones');
        $ayuda->fromArray(array_map(fn ($l) => [$l], [
            'IMPORTADOR DE CORAZONES',
            '',
            '1. Usa la hoja "Corazones". La fila 1 (encabezados) no se puede cambiar: si no es igual a esta plantilla, el archivo se rechaza.',
            '2. Cada fila es UN ingrediente de un corazón. Para un corazón con varios ingredientes, repite su código y nombre en cada fila.',
            '3. CODIGO CORAZON y NOMBRE CORAZON son obligatorios en todas las filas, y el nombre debe ser igual en todas las filas del mismo corazón.',
            '4. DESCRIPCION es opcional (pirámide olfativa). Si un corazón la trae en varias filas, se usa la primera que no esté vacía.',
            '5. CODIGO INGREDIENTE debe ser el código de una materia prima que ya exista. Un corazón no puede ser ingrediente de otro corazón.',
            '6. PORCENTAJE es un número mayor a 0 y hasta 100 (ej. 12.5). No repitas un ingrediente dentro del mismo corazón.',
            '7. Si el código del corazón ya existe, se actualiza: su fórmula se reemplaza por la del archivo. Si no existe, se crea.',
            '8. El corazón queda ACTIVO solo si sus porcentajes suman 100%; si no, queda en borrador.',
            '9. Todo o nada: si una sola fila tiene error, no se importa nada y se te indica la fila.',
            '10. Borra las filas de ejemplo antes de subir tu archivo.',
        ]), null, 'A1');
        $ayuda->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $ayuda->getColumnDimension('A')->setWidth(140);
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'plantilla_corazones.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
