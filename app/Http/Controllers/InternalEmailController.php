<?php

namespace App\Http\Controllers;

use App\Models\InternalEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Correos internos por área (Operaciones, General, Comercial, Desarrollo) que se
 * pueden elegir como destinatarios de una campaña.
 */
class InternalEmailController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:email campaign create');
    }

    public function index(): JsonResponse
    {
        $porArea = InternalEmail::orderBy('email')->get()->groupBy('area');

        $areas = [];
        foreach (InternalEmail::AREAS as $clave => $etiqueta) {
            $areas[] = [
                'key' => $clave,
                'label' => $etiqueta,
                'emails' => ($porArea[$clave] ?? collect())->map(fn ($e) => ['id' => $e->id, 'email' => $e->email])->values(),
            ];
        }

        return response()->json(['data' => $areas]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'area' => ['required', Rule::in(array_keys(InternalEmail::AREAS))],
            'email' => ['required', 'email', 'max:255'],
        ]);
        $data['email'] = strtolower(trim($data['email']));

        // Si ya estaba en esa área no se duplica
        $correo = InternalEmail::firstOrCreate(['area' => $data['area'], 'email' => $data['email']]);

        return response()->json(['data' => ['id' => $correo->id, 'email' => $correo->email, 'area' => $correo->area]], $correo->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(int $id): JsonResponse
    {
        InternalEmail::findOrFail($id)->delete();

        return response()->json(['message' => 'Correo eliminado del área']);
    }
}
