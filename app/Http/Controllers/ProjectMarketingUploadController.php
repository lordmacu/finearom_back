<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectMarketingUploadController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:project edit');
    }

    public function upload(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,pdf',
            'field' => 'required|in:benchmark_examples,catalog_etiquetas,catalog_piramides,lista_presentaciones',
        ]);

        $file = $request->file('file');
        $field = $request->input('field');
        $nombreStorage = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = Storage::disk('local')->putFileAs(
            "marketing-{$field}/{$project->id}",
            $file,
            $nombreStorage
        );

        $marketing = $project->marketingYCalidad()->firstOrCreate(['project_id' => $project->id]);
        $current = $marketing->{$field} ?? [];
        $current[] = $path;
        $marketing->{$field} = $current;
        $marketing->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'path'       => $path,
                'url'        => url("/projects/{$project->id}/marketing-upload/{$field}/" . basename($path)),
                'field'      => $field,
                'nombre'     => $file->getClientOriginalName(),
                'mime_type'  => $file->getMimeType(),
                'size'       => $file->getSize(),
            ],
            'message' => 'Archivo subido',
        ], 201);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $request->validate([
            'field'  => 'required|in:benchmark_examples,catalog_etiquetas,catalog_piramides,lista_presentaciones',
            'path'   => 'required|string',
        ]);

        $marketing = $project->marketingYCalidad()->firstOrCreate(['project_id' => $project->id]);
        $field = $request->input('field');
        $path = $request->input('path');

        $current = $marketing->{$field} ?? [];
        $current = array_values(array_filter($current, fn ($p) => $p !== $path));
        $marketing->{$field} = $current;
        $marketing->save();

        Storage::disk('local')->delete($path);

        return response()->json([
            'success' => true,
            'message' => 'Archivo eliminado',
        ]);
    }

    public function show(Project $project, string $field, string $filename): BinaryFileResponse
    {
        $path = "marketing-{$field}/{$project->id}/{$filename}";
        abort_if(! Storage::disk('local')->exists($path), 404, 'Archivo no encontrado');

        $absolutePath = Storage::disk('local')->path($path);

        return response()->file($absolutePath);
    }
}