<?php

namespace App\Http\Controllers;

use App\Models\EnvelopeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EnvelopeTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = EnvelopeType::where('active', true);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            });
        }

        $perPage = max(1, min(100, (int) $request->query('per_page', 10)));
        $types = $query->orderBy('category')->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $types->items(),
            'meta'    => [
                'current_page' => $types->currentPage(),
                'last_page'    => $types->lastPage(),
                'per_page'     => $types->perPage(),
                'total'        => $types->total(),
            ],
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
