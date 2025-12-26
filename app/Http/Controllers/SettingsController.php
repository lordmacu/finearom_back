<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\RestoreBackupRequest;
use App\Http\Requests\Settings\UpdateProcessesRequest;
use App\Http\Requests\Settings\UpdateTemplatePedidoRequest;
use App\Models\ConfigSystem;
use App\Models\Process as ProcessModel;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class SettingsController extends Controller
{
    private const BACKUP_DIR = 'backups';
    private const DEFAULT_LARAGON_MYSQL_GLOB = 'C:/laragon/bin/mysql/*/bin';

    public function __construct()
    {
        $this->middleware('can:config view')->only(['adminConfiguration', 'listBackups']);
        $this->middleware('can:config edit')->only(['updateProcesses', 'updateTemplatePedido']);
        $this->middleware('can:backup create')->only(['createBackup']);
        $this->middleware('can:backup restore')->only(['restoreBackup']);
    }

    public function adminConfiguration(): JsonResponse
    {
        $processes = ProcessModel::query()
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'process_type', 'created_at', 'updated_at']);

        $template = ConfigSystem::query()
            ->where('key', 'templatePedido')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'processes' => $processes,
                'template_pedido' => $template?->value ?? '',
                'backups' => $this->getBackupFiles(),
            ],
        ]);
    }

    public function updateProcesses(UpdateProcessesRequest $request): JsonResponse
    {
        $rows = $request->validated()['rows'];

        DB::transaction(function () use ($rows) {
            ProcessModel::query()->delete();

            $now = now();
            $payload = array_map(function ($row) use ($now) {
                return [
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'process_type' => $row['process_type'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $rows);

            if (! empty($payload)) {
                ProcessModel::query()->insert($payload);
            }
        });

        // Invalidar caché de emails de proceso
        ProcessEmailController::clearProcessEmailsCache();

        return response()->json([
            'success' => true,
            'message' => 'Configuración de procesos actualizada',
        ]);
    }

    public function updateTemplatePedido(UpdateTemplatePedidoRequest $request): JsonResponse
    {
        $templatePedido = $request->validated()['template_pedido'];

        ConfigSystem::query()->updateOrCreate(
            ['key' => 'templatePedido'],
            ['value' => $templatePedido, 'type' => 'email']
        );

        return response()->json([
            'success' => true,
            'message' => 'Template de pedido actualizado',
        ]);
    }

    public function listBackups(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getBackupFiles(),
        ]);
    }

    public function createBackup(): JsonResponse
    {
        $backupPath = storage_path('app/' . self::BACKUP_DIR . '/');
        if (! is_dir($backupPath)) {
            mkdir($backupPath, 0755, true);
        }

        $dbHost = config('database.connections.mysql.host');
        $dbUsername = config('database.connections.mysql.username');
        $dbPassword = config('database.connections.mysql.password');
        $dbName = config('database.connections.mysql.database');

        $fileName = 'backup_' . Carbon::now()->setTimezone('America/Bogota')->format('Y-m-d_H-i-s') . '.sql';

        $process = new Process([
            $this->mysqlBinary('mysqldump'),
            '--user=' . $dbUsername,
            '--password=' . $dbPassword,
            '--host=' . $dbHost,
            $dbName,
            '--result-file=' . $backupPath . $fileName,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            return response()->json([
                'success' => false,
                'message' => 'Backup falló',
                'error' => $process->getErrorOutput(),
            ], 500);
        }

        $this->deleteOldBackups($backupPath);

        return response()->json([
            'success' => true,
            'message' => 'Backup creado correctamente',
            'data' => [
                'file' => $fileName,
                'backups' => $this->getBackupFiles(),
            ],
        ]);
    }

    public function restoreBackup(RestoreBackupRequest $request): JsonResponse
    {
        $backupFile = $request->validated()['backup'];
        $backupPath = storage_path('app/' . self::BACKUP_DIR . '/') . $backupFile;

        if (! file_exists($backupPath)) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo de backup seleccionado no existe.',
            ], 404);
        }

        $dbHost = config('database.connections.mysql.host');
        $dbUsername = config('database.connections.mysql.username');
        $dbPassword = config('database.connections.mysql.password');
        $dbName = config('database.connections.mysql.database');

        $process = new Process([
            $this->mysqlBinary('mysql'),
            '--user=' . $dbUsername,
            '--password=' . $dbPassword,
            '--host=' . $dbHost,
            $dbName,
            '-e',
            'source ' . $backupPath,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al restaurar la base de datos.',
                'error' => $process->getErrorOutput(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'La base de datos ha sido restaurada exitosamente.',
        ]);
    }

    private function getBackupFiles(): array
    {
        $backupPath = storage_path('app/' . self::BACKUP_DIR . '/');
        if (! is_dir($backupPath)) {
            return [];
        }

        $files = glob($backupPath . '*.sql') ?: [];
        $basenames = array_map('basename', $files);
        rsort($basenames);
        return $basenames;
    }

    private function deleteOldBackups(string $backupPath): void
    {
        $files = glob($backupPath . '*.sql') ?: [];
        $cutoff = Carbon::now()->subWeek()->timestamp;

        foreach ($files as $file) {
            if (@filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function mysqlBinary(string $binary): string
    {
        $configuredBinPath = (string) env('MYSQL_BIN_PATH', '');
        if ($configuredBinPath !== '' && is_dir($configuredBinPath)) {
            $candidate = rtrim($configuredBinPath, '/\\') . DIRECTORY_SEPARATOR . $this->binaryFileName($binary);
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $exeName = $this->binaryFileName($binary);

            $candidates = glob(self::DEFAULT_LARAGON_MYSQL_GLOB . '/' . $exeName) ?: [];
            if (! empty($candidates)) {
                sort($candidates);
                return end($candidates);
            }
        }

        return $binary;
    }

    private function binaryFileName(string $binary): string
    {
        return PHP_OS_FAMILY === 'Windows' ? $binary . '.exe' : $binary;
    }
}
