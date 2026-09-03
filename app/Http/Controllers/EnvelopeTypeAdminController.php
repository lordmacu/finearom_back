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
        $this->middleware('can:settings edit');
    }

    public function index(): JsonResponse
    {
        $types = EnvelopeType::orderBy('category')->orderBy('name')->get();
        return response()->json(['success' => true, 'data' => $types]);
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