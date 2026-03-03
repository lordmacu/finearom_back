<?php

namespace App\Http\Controllers;

use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = ProductCategory::orderBy('name')->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'   => ['required', 'string', 'max:100', 'unique:product_categories,name'],
            'active' => ['boolean'],
        ]);

        $data['slug']   = Str::slug($data['name'], '_');
        $data['active'] = $data['active'] ?? true;

        // Ensure slug is also unique
        $request->validate([
            'name' => ['unique:product_categories,slug,' . $data['slug'] . ',slug'],
        ]);

        $category = ProductCategory::create($data);

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $category = ProductCategory::findOrFail($id);

        $data = $request->validate([
            'name'   => ['required', 'string', 'max:100', 'unique:product_categories,name,' . $id],
            'active' => ['boolean'],
        ]);

        $data['slug'] = Str::slug($data['name'], '_');

        $category->update($data);

        return response()->json(['success' => true, 'data' => $category]);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = ProductCategory::findOrFail($id);
        $category->delete();

        return response()->json(['success' => true]);
    }
}
