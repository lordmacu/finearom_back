<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct()
    {
        $this->middleware('can:user list')->only(['index', 'show']);
        $this->middleware('can:user create')->only(['store']);
        $this->middleware('can:user edit')->only(['update']);
        $this->middleware('can:user delete')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        $search = (string) $request->input('search', '');
        $sortBy = (string) $request->input('sort_by', 'id');
        $sortDirection = (string) $request->input('sort_direction', 'asc');

        $allowedColumns = ['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $allowedColumns, true)) {
            $sortBy = 'id';
        }

        $sortDirection = in_array(strtolower($sortDirection), ['asc', 'desc'], true)
            ? strtolower($sortDirection)
            : 'asc';

        $query = User::query()
            ->with('roles:id,name')
            ->orderBy($sortBy, $sortDirection);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        if ($request->has('per_page') && $perPage !== 'all') {
            $users = $query->paginate((int) $perPage);
        } elseif ($perPage === 'all') {
            return response()->json([
                'success' => true,
                'data' => $query->get(),
            ]);
        } else {
            $users = $query->paginate(15);
        }

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'from' => $users->firstItem(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'to' => $users->lastItem(),
                'total' => $users->total(),
            ],
            'links' => [
                'first' => $users->url(1),
                'last' => $users->url($users->lastPage()),
                'prev' => $users->previousPageUrl(),
                'next' => $users->nextPageUrl(),
            ],
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        if (isset($validated['roles'])) {
            $roles = Role::query()->whereIn('id', $validated['roles'])->get();
            $user->syncRoles($roles);
        }

        $user->load('roles:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado exitosamente',
            'data' => $user,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $user = User::with('roles:id,name')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $user,
        ]);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $validated = $request->validated();

        $updateData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
        ];

        if (array_key_exists('password', $validated) && $validated['password']) {
            $updateData['password'] = $validated['password'];
        }

        $user->update($updateData);

        if (array_key_exists('roles', $validated)) {
            $roleIds = $validated['roles'] ?? [];
            $roles = Role::query()->whereIn('id', $roleIds)->get();
            $user->syncRoles($roles);
        }

        $user->load('roles:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado exitosamente',
            'data' => $user,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if (auth()->id() === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes eliminar tu propio usuario.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Usuario eliminado exitosamente',
        ]);
    }
}

