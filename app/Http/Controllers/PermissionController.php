<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    /**
     * Display a listing of permissions.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search', '');
        $sortBy = $request->input('sort_by', 'id');
        $sortDirection = $request->input('sort_direction', 'asc');
        
        // Validar columnas permitidas para ordenar
        $allowedColumns = ['id', 'name', 'guard_name', 'created_at', 'updated_at'];
        if (!in_array($sortBy, $allowedColumns)) {
            $sortBy = 'id';
        }
        
        // Validar dirección de ordenamiento
        $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc']) 
            ? strtolower($sortDirection) 
            : 'asc';
        
        $query = Permission::orderBy($sortBy, $sortDirection);
        
        if ($search) {
            $query->where('name', 'LIKE', "%{$search}%");
        }
        
        if ($request->has('per_page') && $perPage !== 'all') {
            $permissions = $query->paginate($perPage);
        } else if ($perPage === 'all') {
            $permissions = $query->get();
            return response()->json([
                'success' => true,
                'data' => $permissions
            ]);
        } else {
            $permissions = $query->paginate(15);
        }
        
        return response()->json([
            'success' => true,
            'data' => $permissions->items(),
            'meta' => [
                'current_page' => $permissions->currentPage(),
                'from' => $permissions->firstItem(),
                'last_page' => $permissions->lastPage(),
                'per_page' => $permissions->perPage(),
                'to' => $permissions->lastItem(),
                'total' => $permissions->total(),
            ],
            'links' => [
                'first' => $permissions->url(1),
                'last' => $permissions->url($permissions->lastPage()),
                'prev' => $permissions->previousPageUrl(),
                'next' => $permissions->nextPageUrl(),
            ]
        ]);
    }

    /**
     * Store a newly created permission.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name',
            'guard_name' => 'string|max:255'
        ]);

        $validated['guard_name'] = $validated['guard_name'] ?? 'web';

        $permission = Permission::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Permiso creado exitosamente',
            'data' => $permission
        ], 201);
    }

    /**
     * Display the specified permission.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $permission
        ]);
    }

    /**
     * Update the specified permission.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:permissions,name,' . $id,
            'guard_name' => 'string|max:255'
        ]);

        $permission->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Permiso actualizado exitosamente',
            'data' => $permission
        ]);
    }

    /**
     * Remove the specified permission.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $permission = Permission::findOrFail($id);
        $permission->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permiso eliminado exitosamente'
        ]);
    }
}
