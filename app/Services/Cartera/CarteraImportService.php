<?php

namespace App\Services\Cartera;

use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class CarteraImportService
{
    /**
     * Replica la lógica del import legacy (CarteraImport):
     * - Lee encabezados desde fila 7
     * - Datos desde fila 8
     * - Limpieza/normalización de claves
     * - Filtra por días (mora/cobro)
     *
     * @param array<int,UploadedFile> $files
     * @return array<int,array<string,mixed>>
     */
    public function importFiles(array $files, int $diasMora, int $diasCobro): array
    {
        $all = [];

        foreach ($files as $file) {
            $filename = $file->getClientOriginalName();
            $tipoCartera = stripos($filename, 'EXTERIOR') !== false ? 'internacional' : 'nacional';

            $rows = $this->importFile($file, $diasMora, $diasCobro, $tipoCartera);
            $all = array_merge($all, $rows);
        }

        return $all;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function importFile(UploadedFile $file, int $diasMora, int $diasCobro, string $tipoCartera): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getSheet(0);

        // Row numbers are 1-based.
        $headingRow = 7;
        $startRow = 8;

        // PhpSpreadsheet 2.x does not expose getCellByColumnAndRow; use getCell([$col, $row]).
        // Keep highest column calculation compatible across versions.
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        $highestRow = $sheet->getHighestDataRow();

        $headers = [];
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $rawHeader = (string) $sheet->getCell([$col, $headingRow])->getValue();
            $header = trim($rawHeader);
            if ($header === '') {
                $header = 'nombre_vendedor';
            } else {
                // Match Laravel-Excel heading formatting: slug + underscore + lowercase.
                $header = Str::slug($header, '_');
            }
            $headers[$col] = $header;
        }

        $rows = [];
        for ($rowIndex = $startRow; $rowIndex <= $highestRow; $rowIndex++) {
            $row = [];
            $hasAnyValue = false;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $key = $headers[$col];
                $value = $sheet->getCell([$col, $rowIndex])->getCalculatedValue();

                if (is_string($value)) {
                    $value = trim($value);
                }

                if ($value !== null && $value !== '') {
                    $hasAnyValue = true;
                }

                // Remove null/empty values, same as legacy.
                if ($value === null || $value === '') {
                    continue;
                }

                $row[$key] = $value;
            }

            if (! $hasAnyValue) {
                continue;
            }

            $nit = $row['nit'] ?? null;
            if ($nit === null || (string) $nit === '') {
                continue;
            }
            if (stripos((string) $nit, 'Total') !== false) {
                continue;
            }

            if (! array_key_exists('vencido', $row)) {
                continue;
            }

            $processed = $this->processRow($row, $tipoCartera);

            $dias = (int) ($processed['dias'] ?? 0);
            if ($dias < $diasMora || $dias > $diasCobro) {
                continue;
            }

            if (! array_key_exists('saldo_contable', $processed)) {
                continue;
            }

            $rows[] = $processed;
        }

        // Group by nit and sort by saldo_contable within each group, then sort by dias.
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) ($row['nit'] ?? '')][] = $row;
        }

        $sorted = [];
        foreach ($grouped as $nitKey => $groupRows) {
            usort($groupRows, function ($a, $b) {
                return $this->toSortableNumber($a['saldo_contable'] ?? 0) <=> $this->toSortableNumber($b['saldo_contable'] ?? 0);
            });
            foreach ($groupRows as $r) {
                $sorted[] = $r;
            }
        }

        usort($sorted, function ($a, $b) {
            return ((int) ($a['dias'] ?? 0)) <=> ((int) ($b['dias'] ?? 0));
        });

        return $sorted;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function processRow(array $row, string $tipoCartera): array
    {
        $nitField = (string) ($row['nit'] ?? '');
        $nitField = trim($nitField);

        $nitParts = preg_split('/\\s+/', $nitField, 2) ?: [];
        $nitNumber = $nitParts[0] ?? '';
        $companyName = $nitParts[1] ?? '';
        $companyName = trim(str_replace('000', '', $companyName));

        $row['fecha'] = $this->convertExcelDate($row['fecha'] ?? null);
        $row['vence'] = $this->convertExcelDate($row['vence'] ?? null);

        $row['nit'] = $nitNumber;
        $row['catera_type'] = $tipoCartera;
        $row['nombre_empresa'] = $companyName;

        return $row;
    }

    private function convertExcelDate(mixed $excelDate): ?string
    {
        if (is_numeric($excelDate)) {
            return Carbon::createFromFormat('Y-m-d', '1900-01-01')
                ->addDays(((int) $excelDate) - 2)
                ->format('Y-m-d');
        }

        // Sometimes the cell is already a string date like 2025-12-01
        $stringValue = is_string($excelDate) ? trim($excelDate) : null;
        if ($stringValue) {
            try {
                return Carbon::parse($stringValue)->toDateString();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function toSortableNumber(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return 0.0;
        }

        if (str_contains($stringValue, ',') && str_contains($stringValue, '.')) {
            $stringValue = str_replace('.', '', $stringValue);
            $stringValue = str_replace(',', '.', $stringValue);
        } elseif (str_contains($stringValue, ',')) {
            $stringValue = str_replace(',', '.', $stringValue);
        }

        $stringValue = preg_replace('/[^0-9.\\-]/', '', $stringValue) ?? $stringValue;
        return (float) $stringValue;
    }
}
