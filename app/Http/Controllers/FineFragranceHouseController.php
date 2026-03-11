<?php

namespace App\Http\Controllers;

use App\Http\Requests\FineFragranceHouse\FineFragranceHouseStoreRequest;
use App\Http\Requests\FineFragranceHouse\FineFragranceHouseUpdateRequest;
use App\Models\FineFragranceHouse;
use Illuminate\Http\JsonResponse;

class FineFragranceHouseController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:fine fragrance list')->only(['index', 'show']);
        $this->middleware('can:fine fragrance create')->only(['store']);
        $this->middleware('can:fine fragrance edit')->only(['update']);
        $this->middleware('can:fine fragrance delete')->only(['destroy']);
    }

    public function index(): JsonResponse
    {
        $houses = FineFragranceHouse::orderBy('nombre')->get();

        return response()->json(['data' => $houses], 200);
    }

    public function store(FineFragranceHouseStoreRequest $request): JsonResponse
    {
        $house = FineFragranceHouse::create($request->validated());

        return response()->json(['data' => $house, 'message' => 'Casa creada correctamente'], 201);
    }

    public function show(FineFragranceHouse $fineFragranceHouse): JsonResponse
    {
        $fineFragranceHouse->load('fragrances');

        return response()->json(['data' => $fineFragranceHouse], 200);
    }

    public function update(FineFragranceHouseUpdateRequest $request, FineFragranceHouse $fineFragranceHouse): JsonResponse
    {
        $fineFragranceHouse->update($request->validated());

        return response()->json(['data' => $fineFragranceHouse, 'message' => 'Casa actualizada correctamente'], 200);
    }

    public function destroy(FineFragranceHouse $fineFragranceHouse): JsonResponse
    {
        $fineFragranceHouse->delete();

        return response()->json(['message' => 'Casa eliminada correctamente'], 200);
    }
}
