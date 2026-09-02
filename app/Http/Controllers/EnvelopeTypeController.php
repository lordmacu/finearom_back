<?php

namespace App\Http\Controllers;

use App\Models\EnvelopeType;
use Illuminate\Http\JsonResponse;

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
}