<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SalesForecastImportController extends Controller
{
    /**
     * POST /sales-forecasts/preview
     * Sube el Excel, corre el script Python con --dry-run y devuelve preview JSON.
     * NO toca la BD.
     */
    public function preview(Request $request): JsonResponse
    {
        return $this->runScript($request, dryRun: true);
    }

    /**
     * POST /sales-forecasts/import
     * Sube el Excel y corre el script Python en modo real.
     * DELETE FROM sales_forecasts WHERE modelo='manual' + INSERT de todos los pronósticos.
     */
    public function import(Request $request): JsonResponse
    {
        return $this->runScript($request, dryRun: false);
    }

    private function runScript(Request $request, bool $dryRun): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:51200'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $mode = $dryRun ? 'preview' : 'import';
        Log::info("[ForecastImport] {$mode} iniciado", [
            'user'      => auth()->id(),
            'file_name' => $request->file('file')->getClientOriginalName(),
            'file_size' => $request->file('file')->getSize(),
            'year'      => $request->input('year'),
        ]);

        $tmpPath  = $request->file('file')->storeAs('', uniqid('forecast_') . '.xlsx', 'local');
        $fullPath = storage_path('app/' . $tmpPath);

        $scriptPath = base_path('scripts/import_manual_forecasts.py');
        $python     = $this->resolvePython();

        $args = [escapeshellarg($fullPath)];
        if ($dryRun) {
            $args[] = '--dry-run';
        }
        if ($request->filled('year')) {
            $args[] = '--year=' . (int) $request->input('year');
        }

        $command = escapeshellcmd("$python $scriptPath") . ' ' . implode(' ', $args) . ' 2>&1';
        $output  = shell_exec($command);

        @unlink($fullPath);

        Log::info("[ForecastImport] {$mode} salida Python", ['output' => substr((string) $output, 0, 4000)]);

        if (!$output) {
            return response()->json([
                'ok'      => false,
                'message' => 'El script no devolvió respuesta.',
            ], 500);
        }

        $jsonLine = $this->extractJsonLine($output);
        if ($jsonLine === null) {
            return response()->json([
                'ok'      => false,
                'message' => 'Respuesta no parseable del script.',
                'raw'     => mb_substr(trim($output), 0, 2000),
            ], 500);
        }

        $decoded = json_decode($jsonLine, true);
        if (!is_array($decoded)) {
            return response()->json([
                'ok'      => false,
                'message' => 'JSON inválido del script.',
                'raw'     => mb_substr($jsonLine, 0, 2000),
            ], 500);
        }

        if (empty($decoded['ok'])) {
            return response()->json($decoded, 422);
        }

        return response()->json($decoded);
    }

    private function extractJsonLine(string $output): ?string
    {
        $lines = array_reverse(preg_split('/\r\n|\n|\r/', trim($output)) ?: []);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($line[0] === '{' && substr($line, -1) === '}') {
                return $line;
            }
        }
        return null;
    }

    private function resolvePython(): string
    {
        foreach (['python3', 'python'] as $bin) {
            $path = trim((string) shell_exec("which $bin 2>/dev/null"));
            if ($path !== '') {
                return $path;
            }
        }
        return 'python3';
    }
}
