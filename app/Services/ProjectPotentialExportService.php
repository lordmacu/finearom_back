<?php

namespace App\Services;

use App\Models\Project;
use App\Support\PotentialDispatchPlan;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Descarga de Potencial a la vista con el formato de la hoja "POTENCIAL A LA
 * VISTA" del Excel de proyectos: una fila por referencia seleccionada, los
 * datos hasta COMENTARIOS O ESTADO y los meses del año elegido con su total
 * y probabilidad.
 */
class ProjectPotentialExportService
{
    private const MESES = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

    private const ORIGENES = ['proactivo' => 'PROACTIVO', 'reactivo' => 'REACTIVO', 'homologacion' => 'HOMOLOGACIÓN'];

    private const FRECUENCIAS = [
        'una_vez' => 'UNA VEZ', 'mensual' => 'MENSUAL', 'bimensual' => 'BIMENSUAL', 'trimestral' => 'TRIMESTRAL',
        'cuatrimestral' => 'CUATRIMESTRAL', 'semestral' => 'SEMESTRAL', 'anual' => 'ANUAL',
    ];

    public function __construct(
        private readonly ProjectPotentialService $potential
    ) {}

    public function build(?string $ejecutivo, ?string $estadoExterno, int $anio): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('POTENCIAL A LA VISTA');
        $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $encabezados = $this->encabezados($anio);
        $sheet->fromArray($encabezados, null, 'A1');

        $filas = $this->filas($ejecutivo, $estadoExterno, $anio);
        if ($filas) {
            $sheet->fromArray($filas, null, 'A2', true);
        }

        $this->darFormato($sheet, count($encabezados), count($filas));

        return $spreadsheet;
    }

    private function encabezados(int $anio): array
    {
        $base = [
            'EJECUTIVA', 'NÚMERO PROYECTO', 'CLIENTE', 'PROYECTO', 'REFERENCIA - CODIGO', 'SEGMENTO',
            'TIPO PROYECTO', 'KG AÑO', 'PRECIO USD', 'POTENCIAL ANUAL USD', 'FECHA PRIMER DESPACHO',
            "VENTA {$anio}", 'FRECUENCIA COMPRA AÑO', 'SEGUIMIENTO',
            'COMENTARIOS O ESTADO ( ABIERTO, GANADO, PERDIDO o CANCELADO.)',
        ];

        return array_merge($base, $this->encabezadosBloque($anio));
    }

    private function encabezadosBloque(int $anio): array
    {
        $columnas = [];
        foreach (self::MESES as $mes) {
            $columnas[] = "{$mes} KG {$anio}";
            $columnas[] = "{$mes} USD {$anio}";
        }

        return array_merge($columnas, ["TOTAL VENTA ESTIMADA AÑO {$anio} USD", 'PROBABILIDAD']);
    }

    private function filas(?string $ejecutivo, ?string $estadoExterno, int $anio): array
    {
        $filas = [];

        $proyectos = $this->potential->query($ejecutivo, $estadoExterno)
            ->whereHas('potentialReferences')
            ->orderBy('ejecutivo')
            ->orderByDesc('id')
            ->get();

        foreach ($proyectos as $project) {
            foreach ($project->marketingVariants as $variant) {
                foreach ($variant->references as $ref) {
                    if (!$s = $ref->potential) {
                        continue;
                    }

                    $kg     = $s->kg_anio !== null ? (float) $s->kg_anio : null;
                    $precio = $ref->precio !== null ? (float) $ref->precio : null;
                    $probabilidad = $s->probabilidad ? mb_strtoupper($s->probabilidad) : null;

                    $fila = [
                        mb_strtoupper((string) $project->ejecutivo),
                        $project->id,
                        $this->cliente($project),
                        $project->nombre,
                        trim(implode(' ', array_filter([$ref->codigo, $ref->referencia]))),
                        $project->productCategory?->name ? mb_strtoupper($project->productCategory->name) : null,
                        self::ORIGENES[$this->potential->origen($project)],
                        $kg,
                        $precio,
                        $kg !== null && $precio !== null ? round($kg * $precio, 2) : null,
                        $s->fecha_primer_despacho
                            ? self::MESES[$s->fecha_primer_despacho->month - 1] . ' ' . $s->fecha_primer_despacho->year
                            : null,
                        $s->venta_anio_usd !== null ? (float) $s->venta_anio_usd : null,
                        self::FRECUENCIAS[$s->frecuencia_compra] ?? null,
                        $s->seguimiento,
                        mb_strtoupper($s->estado),
                    ];

                    $plan = PotentialDispatchPlan::for($kg, $precio, $s->frecuencia_compra, $s->fecha_primer_despacho, $anio);
                    foreach (range(0, 11) as $i) {
                        $mes    = $plan['meses'][$i] ?? null;
                        $fila[] = $mes && $mes['kg'] > 0 ? $mes['kg'] : null;
                        $fila[] = $mes && $mes['kg'] > 0 ? $mes['usd'] : null;
                    }
                    $fila[] = $plan['total_usd'] ?? null;
                    $fila[] = $probabilidad;

                    $filas[] = $fila;
                }
            }
        }

        return $filas;
    }

    private function cliente(Project $project): ?string
    {
        return $project->client?->client_name ?? $project->prospect?->nombre ?? $project->nombre_prospecto;
    }

    private function darFormato($sheet, int $columnas, int $filas): void
    {
        $ultimaColumna = Coordinate::stringFromColumnIndex($columnas);
        $ultimaFila    = max(1, $filas + 1);
        $bordes        = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]];

        $sheet->getStyle("A1:{$ultimaColumna}1")->applyFromArray($bordes + [
            'font'      => ['color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '203764']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(42);

        if ($filas > 0) {
            $sheet->getStyle("A2:{$ultimaColumna}{$ultimaFila}")->applyFromArray($bordes + [
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            // Kg y USD con separador de miles; precio con dos decimales
            $sheet->getStyle("H2:H{$ultimaFila}")->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle("I2:L{$ultimaFila}")->getNumberFormat()->setFormatCode('"$"#,##0.00');
            $sheet->getStyle("P2:{$ultimaColumna}{$ultimaFila}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $anchos = ['A' => 18.43, 'B' => 12, 'C' => 28, 'D' => 28, 'E' => 31.43, 'F' => 14, 'G' => 14,
            'H' => 10.29, 'I' => 10.29, 'J' => 12.86, 'K' => 14, 'L' => 12, 'M' => 14, 'N' => 24, 'O' => 20.71];
        foreach ($anchos as $columna => $ancho) {
            $sheet->getColumnDimension($columna)->setWidth($ancho);
        }
        foreach (range(16, $columnas) as $i) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(14);
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$ultimaColumna}{$ultimaFila}");
    }
}
