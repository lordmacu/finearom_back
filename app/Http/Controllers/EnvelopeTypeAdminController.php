<?php

namespace App\Http\Controllers;

use App\Models\EnvelopeType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EnvelopeTypeAdminController extends Controller
{
    public function __construct()
    {
        // Módulo aparte de administración de envases: crear/editar/eliminar
        // es solo para quien tenga 'envelope type manage' (Marketing y roles
        // gerenciales). El listado público de solo-lectura para elegir envase
        // en el formulario de proyecto vive en EnvelopeTypeController, sin
        // esta restricción.
        $this->middleware('can:envelope type manage');
    }

    public function index(Request $request): JsonResponse
    {
        $query = EnvelopeType::query();

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'category' => 'nullable|string|max:100',
            'photo'    => 'nullable|image|max:5120',
            'active'   => 'nullable|boolean',
        ]);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->storeAs(
                'envelope-photos',
                Str::uuid() . '.' . $request->file('photo')->getClientOriginalExtension(),
                'local'
            );
            $data['photo_path'] = $path;
        }

        $type = EnvelopeType::create($data);
        return response()->json(['success' => true, 'data' => $type, 'message' => 'Tipo de envase creado'], 201);
    }

    public function update(Request $request, EnvelopeType $envelopeType): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:100',
            'category' => 'nullable|string|max:100',
            'photo'    => 'nullable|image|max:5120',
            'active'   => 'nullable|boolean',
        ]);

        if ($request->hasFile('photo')) {
            if ($envelopeType->photo_path) {
                Storage::disk('local')->delete($envelopeType->photo_path);
            }
            $path = $request->file('photo')->storeAs(
                'envelope-photos',
                Str::uuid() . '.' . $request->file('photo')->getClientOriginalExtension(),
                'local'
            );
            $data['photo_path'] = $path;
        }

        $envelopeType->update($data);
        return response()->json(['success' => true, 'data' => $envelopeType->fresh(), 'message' => 'Tipo de envase actualizado']);
    }

    public function destroy(EnvelopeType $envelopeType): JsonResponse
    {
        if ($envelopeType->photo_path) {
            Storage::disk('local')->delete($envelopeType->photo_path);
        }
        $envelopeType->delete();
        return response()->json(['success' => true, 'message' => 'Tipo de envase eliminado']);
    }
}