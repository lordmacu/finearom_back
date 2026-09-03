<?php

namespace App\Http\Controllers;

use App\Models\EnvelopeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EnvelopeTypeController extends Controller
{
    public function index(): JsonResponse
    {
        $types = EnvelopeType::where('active', true)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $types,
        ]);
    }

    /**
     * Sirve la foto del envase. photo_path vive en el disco `local`, que no es
     * público, así que el archivo sólo sale por aquí.
     */
    public function photo(EnvelopeType $envelopeType): BinaryFileResponse
    {
        abort_if(! $envelopeType->photo_path, 404, 'El tipo de envase no tiene foto');
        abort_if(! Storage::disk('local')->exists($envelopeType->photo_path), 404, 'Archivo no encontrado');

        return response()->file(Storage::disk('local')->path($envelopeType->photo_path));
    }
}
