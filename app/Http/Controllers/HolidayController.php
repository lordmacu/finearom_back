<?php

namespace App\Http\Controllers;

use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:config view')->only(['index']);
        $this->middleware('can:config edit')->only(['store', 'update', 'destroy']);
    }

    public function index(): JsonResponse
    {
        $holidays = Holiday::orderBy('date')->get();

        return response()->json(['success' => true, 'data' => $holidays]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'   => ['required', 'date', 'unique:holidays,date'],
            'nombre' => ['required', 'string', 'max:150'],
        ]);

        $holiday = Holiday::create($data);

        return response()->json(['success' => true, 'data' => $holiday, 'message' => 'Feriado creado'], 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $data = $request->validate([
            'date'   => ['required', 'date', 'unique:holidays,date,' . $holiday->id],
            'nombre' => ['required', 'string', 'max:150'],
        ]);

        $holiday->update($data);

        return response()->json(['success' => true, 'data' => $holiday->fresh(), 'message' => 'Feriado actualizado']);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $holiday->delete();

        return response()->json(['success' => true, 'message' => 'Feriado eliminado']);
    }
}
