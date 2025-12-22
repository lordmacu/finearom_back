<?php

namespace App\Http\Controllers\Uploads;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class CkeditorUploadController extends Controller
{
    /**
     * Store uploaded image from CKEditor (nuevo backend)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload' => ['required', 'file', 'image', 'max:5120'],
        ]);

        $path = $validated['upload']->store('ckeditor', 'public');

        return response()->json([
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }

    /**
     * Legacy upload - soporta tanto archivo como URL remota
     */
    public function legacyUpload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'upload' => 'nullable|file|image|max:5120',
            'url' => 'nullable|url',
        ]);

        if ($request->hasFile('upload')) {
            $path = $request->file('upload')->store('uploads/ckeditor', 'public');
        } elseif ($request->filled('url')) {
            $response = Http::timeout(10)->get($request->input('url'));

            if (!$response->successful()) {
                return response()->json(['message' => 'Could not fetch remote image'], 422);
            }

            $mime = $response->header('Content-Type');
            if (!str_starts_with($mime, 'image/')) {
                return response()->json(['message' => 'URL is not an image'], 422);
            }

            $extension = explode('/', $mime)[1] ?? 'jpg';
            $filename = 'uploads/ckeditor/' . uniqid('remote_', true) . '.' . $extension;
            Storage::disk('public')->put($filename, $response->body());
            $path = $filename;
        } else {
            return response()->json(['message' => 'No image provided'], 422);
        }

        return response()->json([
            'url' => asset('storage/' . $path),
        ]);
    }
}

