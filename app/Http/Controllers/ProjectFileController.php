<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectFile;
use App\Services\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectFileController extends Controller
{
    public function __construct(
        private readonly GoogleDriveService $driveService,
    ) {
        $this->middleware('can:project list')->only(['index', 'download']);
        $this->middleware('can:project edit')->only(['store', 'destroy']);
    }

    public function index(Project $project): JsonResponse
    {
        $files = $project->files()->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data'    => $files,
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'archivo'   => 'required|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,zip',
            'categoria' => 'nullable|string|in:ficha_tecnica,formulacion,aprobacion_cliente,msds,otro',
        ]);

        $archivo = $request->file('archivo');

        $nombreOriginal  = $archivo->getClientOriginalName();
        $nombreStorage   = Str::uuid() . '.' . $archivo->getClientOriginalExtension();
        $directorio      = "project-files/{$project->id}";
        $path            = Storage::disk('local')->putFileAs($directorio, $archivo, $nombreStorage);

        $driveFileId = null;
        $driveLink   = null;

        // Subir a Google Drive en Proyectos/[Proyecto]/[Categoría] (silencioso)
        try {
            $userId = auth()->id();
            if ($this->driveService->hasDriveAccess($userId)) {
                $projectName = $project->nombre ?? "Proyecto {$project->id}";
                $projectFolder = $this->driveService->getOrCreateProjectFolder($userId, $projectName);
                $folder = $this->driveService->getOrCreateProjectCategoryFolder(
                    $userId,
                    $projectName,
                    $request->input('categoria')
                );
                $result = $this->driveService->uploadFromStorage($userId, 'local', $path, $nombreOriginal, $folder);
                if ($result && !empty($result['id'])) {
                    $this->driveService->makePublic($userId, $result['id']);
                    $driveFileId = $result['id'];
                    $driveLink   = $result['webViewLink'] ?? null;
                }
                // Guardar link de la carpeta del proyecto si aún no lo tiene
                if ($projectFolder && !$project->drive_folder_link) {
                    $project->drive_folder_link = "https://drive.google.com/drive/folders/{$projectFolder}";
                    $project->saveQuietly();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GoogleDrive: fallo al subir archivo de proyecto: ' . $e->getMessage());
        }

        $file = ProjectFile::create([
            'project_id'      => $project->id,
            'nombre_original' => $nombreOriginal,
            'nombre_storage'  => $nombreStorage,
            'path'            => $path,
            'mime_type'       => $archivo->getMimeType(),
            'size'            => $archivo->getSize(),
            'categoria'       => $request->input('categoria'),
            'ejecutivo'       => auth()->user()->name,
            'drive_file_id'   => $driveFileId,
            'drive_link'      => $driveLink,
        ]);

        return response()->json([
            'success' => true,
            'data'    => $file,
            'message' => 'Archivo subido correctamente',
        ], 201);
    }

    public function destroy(Project $project, ProjectFile $file): JsonResponse
    {
        abort_if($file->project_id !== $project->id, 404);

        Storage::disk('local')->delete($file->path);
        $file->delete();

        return response()->json([
            'success' => true,
            'message' => 'Archivo eliminado',
        ]);
    }

    public function download(Project $project, ProjectFile $file): BinaryFileResponse
    {
        abort_if($file->project_id !== $project->id, 404);

        $absolutePath = Storage::disk('local')->path($file->path);

        abort_if(! file_exists($absolutePath), 404, 'Archivo no encontrado en el servidor');

        return response()->download($absolutePath, $file->nombre_original);
    }
}
