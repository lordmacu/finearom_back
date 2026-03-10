<?php

namespace App\Services;

use App\Models\UserGoogleToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Servicio para subir archivos a Google Drive.
 *
 * Estructura de carpetas en Drive:
 *
 * Finearom - Documentos/
 *   └── [Cliente ABC (NIT: 123)]/
 *         ├── Órdenes/
 *         │     └── OC-001/
 *         │           ├── proforma-nacional-001.pdf
 *         │           ├── adjunto-1.pdf
 *         │           └── adjunto-2.pdf
 *         └── Documentos Legales/
 *               ├── RUT.pdf
 *               └── Camara de Comercio.pdf
 *   └── Proyectos/
 *         └── [Nombre Proyecto]/
 *               ├── Ficha Técnica/
 *               ├── Formulación/
 *               └── Otro/
 *
 * TODOS los métodos son silenciosos: capturan excepciones y retornan null en caso de fallo.
 */
class GoogleDriveService
{
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
    private const FILES_URL   = 'https://www.googleapis.com/drive/v3/files';
    private const UPLOAD_URL  = 'https://www.googleapis.com/upload/drive/v3/files';

    // ─── Verificación de acceso ───────────────────────────────────────────────

    public function hasDriveAccess(int $userId): bool
    {
        $token = UserGoogleToken::where('user_id', $userId)->first();
        if (!$token) return false;
        return in_array(self::DRIVE_SCOPE, $token->scopes ?? []);
    }

    public function getAnyUserWithDriveAccess(): ?int
    {
        foreach (UserGoogleToken::whereNotNull('scopes')->get() as $token) {
            if (in_array(self::DRIVE_SCOPE, $token->scopes ?? [])) {
                return $token->user_id;
            }
        }
        return null;
    }

    // ─── Upload ───────────────────────────────────────────────────────────────

    /**
     * Sube contenido binario a Drive.
     * Retorna ['id' => ..., 'webViewLink' => ...] o null si falla.
     */
    public function uploadFile(int $userId, string $binaryContent, string $filename, ?string $folderId = null, string $mimeType = 'application/pdf'): ?array
    {
        try {
            $accessToken = $this->getValidToken($userId);

            $metadata = ['name' => $filename, 'mimeType' => $mimeType];
            if ($folderId) {
                $metadata['parents'] = [$folderId];
            }

            $boundary = 'finearom_' . uniqid();
            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
            $body .= json_encode($metadata) . "\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: {$mimeType}\r\n\r\n";
            $body .= $binaryContent . "\r\n";
            $body .= "--{$boundary}--";

            $response = Http::withToken($accessToken)
                ->withBody($body, "multipart/related; boundary={$boundary}")
                ->post(self::UPLOAD_URL . '?uploadType=multipart&fields=id,webViewLink');

            if ($response->failed()) {
                Log::warning("GoogleDrive: fallo al subir '{$filename}' para user {$userId}: " . $response->body());
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning("GoogleDrive: excepción al subir '{$filename}': " . $e->getMessage());
            return null;
        }
    }

    /**
     * Lee un archivo desde Laravel Storage y lo sube a Drive.
     * Útil para archivos ya guardados en disco (adjuntos de órdenes, archivos de proyectos).
     */
    public function uploadFromStorage(int $userId, string $disk, string $storagePath, string $filename, ?string $folderId = null): ?array
    {
        try {
            $binaryContent = Storage::disk($disk)->get($storagePath);
            if ($binaryContent === null) {
                Log::warning("GoogleDrive: archivo no encontrado en storage: {$storagePath}");
                return null;
            }

            $mimeType = $this->guessMimeType($filename);
            return $this->uploadFile($userId, $binaryContent, $filename, $folderId, $mimeType);
        } catch (\Throwable $e) {
            Log::warning("GoogleDrive: excepción al leer storage '{$storagePath}': " . $e->getMessage());
            return null;
        }
    }

    // ─── Jerarquía de carpetas ────────────────────────────────────────────────

    /**
     * Raíz: "Finearom - Documentos"
     */
    public function getRootFolder(int $userId): ?string
    {
        return $this->getOrCreate($userId, 'Finearom - Documentos', null);
    }

    /**
     * Carpeta de cliente: [Raíz] / "NombreCliente (NIT: xxx)"
     * Guarda el link de la carpeta en el campo drive_folder_link del cliente.
     */
    public function getOrCreateClientFolder(int $userId, \App\Models\Client $client): ?string
    {
        $rootId = $this->getRootFolder($userId);
        if (!$rootId) return null;

        $name = $client->client_name . ($client->nit ? ' (NIT: ' . $client->nit . ')' : '');
        $folderId = $this->getOrCreate($userId, $name, $rootId);

        if ($folderId && !$client->drive_folder_link) {
            $link = "https://drive.google.com/drive/folders/{$folderId}";
            $client->drive_folder_link = $link;
            $client->saveQuietly();
        }

        return $folderId;
    }

    /**
     * Carpeta de orden: [Cliente] / "Órdenes" / "OC-001"
     * Aquí van: proforma + adjuntos de esa orden.
     */
    public function getOrCreateOrderFolder(int $userId, \App\Models\Client $client, string $orderConsecutive): ?string
    {
        $clientId = $this->getOrCreateClientFolder($userId, $client);
        if (!$clientId) return null;

        $ordenesId = $this->getOrCreate($userId, 'Órdenes', $clientId);
        if (!$ordenesId) return null;

        return $this->getOrCreate($userId, $orderConsecutive, $ordenesId);
    }

    /**
     * Carpeta de documentos legales: [Cliente] / "Documentos Legales"
     * Aquí van: RUT, Cámara de Comercio, Cédula, etc.
     */
    public function getOrCreateClientDocFolder(int $userId, \App\Models\Client $client): ?string
    {
        $clientId = $this->getOrCreateClientFolder($userId, $client);
        if (!$clientId) return null;

        return $this->getOrCreate($userId, 'Documentos Legales', $clientId);
    }

    /**
     * Carpeta de proyecto: [Raíz] / "Proyectos" / "Nombre del Proyecto"
     */
    public function getOrCreateProjectFolder(int $userId, string $projectName): ?string
    {
        $rootId = $this->getRootFolder($userId);
        if (!$rootId) return null;

        $proyectosId = $this->getOrCreate($userId, 'Proyectos', $rootId);
        if (!$proyectosId) return null;

        return $this->getOrCreate($userId, $projectName, $proyectosId);
    }

    /**
     * Carpeta de categoría dentro de un proyecto: [Raíz] / "Proyectos" / "Proyecto" / "Categoría"
     * categorias: ficha_tecnica → "Ficha Técnica", formulacion → "Formulación", etc.
     */
    public function getOrCreateProjectCategoryFolder(int $userId, string $projectName, ?string $categoria): ?string
    {
        $projectFolderId = $this->getOrCreateProjectFolder($userId, $projectName);
        if (!$projectFolderId) return null;

        $catName = $this->categoriaNombre($categoria);
        return $this->getOrCreate($userId, $catName, $projectFolderId);
    }

    // ─── Permisos ─────────────────────────────────────────────────────────────

    /**
     * Hace un archivo accesible para cualquier persona con el link (solo lectura).
     */
    public function makePublic(int $userId, string $fileId): bool
    {
        try {
            $accessToken = $this->getValidToken($userId);
            $response = Http::withToken($accessToken)
                ->post(self::FILES_URL . "/{$fileId}/permissions", [
                    'type' => 'anyone',
                    'role' => 'reader',
                ]);
            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("GoogleDrive: fallo al hacer público {$fileId}: " . $e->getMessage());
            return false;
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Obtiene o crea una carpeta por nombre dentro de un padre. Cachea en memoria por request.
     */
    private function getOrCreate(int $userId, string $folderName, ?string $parentId): ?string
    {
        $existing = $this->findFolder($userId, $folderName, $parentId);
        return $existing ?? $this->createFolder($userId, $folderName, $parentId);
    }

    private function findFolder(int $userId, string $folderName, ?string $parentId): ?string
    {
        try {
            $accessToken = $this->getValidToken($userId);
            $parentQuery = $parentId ? " and '{$parentId}' in parents" : " and 'root' in parents";
            $q = "mimeType='application/vnd.google-apps.folder' and name='" . addslashes($folderName) . "' and trashed=false{$parentQuery}";

            $response = Http::withToken($accessToken)
                ->get(self::FILES_URL, ['q' => $q, 'fields' => 'files(id)', 'pageSize' => 1]);

            if ($response->failed()) return null;
            $files = $response->json('files', []);
            return $files[0]['id'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function createFolder(int $userId, string $folderName, ?string $parentId): ?string
    {
        try {
            $accessToken = $this->getValidToken($userId);
            $metadata = ['name' => $folderName, 'mimeType' => 'application/vnd.google-apps.folder'];
            if ($parentId) $metadata['parents'] = [$parentId];

            $response = Http::withToken($accessToken)
                ->post(self::FILES_URL . '?fields=id', $metadata);

            if ($response->failed()) {
                Log::warning("GoogleDrive: fallo al crear carpeta '{$folderName}': " . $response->body());
                return null;
            }
            return $response->json('id');
        } catch (\Throwable $e) {
            Log::warning("GoogleDrive: excepción al crear carpeta '{$folderName}': " . $e->getMessage());
            return null;
        }
    }

    private function guessMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match($ext) {
            'pdf'             => 'application/pdf',
            'jpg', 'jpeg'     => 'image/jpeg',
            'png'             => 'image/png',
            'doc'             => 'application/msword',
            'docx'            => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'             => 'application/vnd.ms-excel',
            'xlsx'            => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip'             => 'application/zip',
            default           => 'application/octet-stream',
        };
    }

    private function categoriaNombre(?string $categoria): string
    {
        return match($categoria) {
            'ficha_tecnica'      => 'Ficha Técnica',
            'formulacion'        => 'Formulación',
            'aprobacion_cliente' => 'Aprobación Cliente',
            'msds'               => 'MSDS',
            default              => 'Otro',
        };
    }

    // ─── Token ────────────────────────────────────────────────────────────────

    private function getValidToken(int $userId): string
    {
        $token = UserGoogleToken::where('user_id', $userId)->firstOrFail();

        if ($token->expires_at->isPast()) {
            $response = Http::post(self::TOKEN_URL, [
                'client_id'     => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => decrypt($token->refresh_token),
                'grant_type'    => 'refresh_token',
            ]);

            if ($response->failed()) {
                $token->delete();
                throw new \RuntimeException('La sesión de Google expiró. Por favor reconecta tu cuenta.');
            }

            $tokens = $response->json();
            $token->update([
                'access_token' => encrypt($tokens['access_token']),
                'expires_at'   => now()->addSeconds($tokens['expires_in'] - 60),
            ]);
            return $tokens['access_token'];
        }

        return decrypt($token->access_token);
    }
}
