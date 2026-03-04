# AGENTS.md — Backend (Laravel 10)

API REST del proyecto Finearom. PHP 8.1+, MySQL 8, Sanctum, Spatie Permissions.

## Estructura

```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/   → Un controller por recurso
│   │   └── Requests/      → {Entidad}/{Entidad}StoreRequest.php + UpdateRequest.php
│   ├── Models/            → Eloquent models con $fillable y relaciones tipadas
│   ├── Services/          → Lógica de negocio compleja
│   └── Queries/{Módulo}/  → SQL complejo con DB::select() y bindings
├── database/
│   ├── migrations/        → Leer DATABASE.md antes de crear nuevas
│   └── factories/
└── routes/
    └── api.php            → Todas las rutas agrupadas con auth:sanctum
```

## Reglas críticas

1. **Form Requests** — NUNCA validar en el controller. Siempre usar Form Request
2. **JsonResponse** — TODO retorno debe ser `JsonResponse` tipado
3. **Permisos** — siempre en constructor con middleware, nunca inline
4. **SQL** — NUNCA concatenar strings. Siempre bindings nombrados en DB::select()
5. **Debug** — NUNCA `dd()`, `var_dump()`, `print_r()` en código commiteable
6. **Lógica** — NUNCA lógica de negocio en controllers → mover a Services o Queries

## Plantilla Controller

```php
class NombreController extends Controller
{
    public function __construct(
        private readonly NombreService $service
    ) {
        $this->middleware('can:nombre list')->only(['index', 'show']);
        $this->middleware('can:nombre create')->only(['store']);
        $this->middleware('can:nombre edit')->only(['update']);
        $this->middleware('can:nombre delete')->only(['destroy']);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->service->list($request->all());
        return response()->json(['data' => $data], 200);
    }

    public function store(NombreStoreRequest $request): JsonResponse
    {
        $item = $this->service->create($request->validated());
        return response()->json(['data' => $item, 'message' => 'Creado'], 201);
    }

    public function update(NombreUpdateRequest $request, int $id): JsonResponse
    {
        $item = $this->service->update($id, $request->validated());
        return response()->json(['data' => $item, 'message' => 'Actualizado'], 200);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);
        return response()->json(['message' => 'Eliminado'], 200);
    }
}
```

## Plantilla Form Request

```php
class NombreStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'campo' => 'required|string|max:255',
            'otro'  => 'nullable|integer',
        ];
    }
}
```

## Plantilla Route

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('nombre', NombreController::class);
    // Rutas extra no-CRUD:
    Route::post('nombre/{id}/accion', [NombreController::class, 'accion']);
});
```

## Responses estándar

```php
// Éxito
response()->json(['data' => $resource], 200);

// Creación
response()->json(['data' => $resource, 'message' => 'Creado'], 201);

// Error de negocio
response()->json(['message' => 'Descripción clara del error'], 422);

// No encontrado
response()->json(['message' => 'No encontrado'], 404);
```

## Antes de crear migrations

1. Leer `DATABASE.md` — verificar si la tabla ya existe o si hay convenciones de naming
2. Revisar `database/migrations/` — evitar duplicar columnas
3. Naming: tablas en `snake_case` plural, columnas en `snake_case`, FKs como `{tabla_singular}_id`
4. Siempre incluir `down()` que revierte exactamente el `up()`

## Comandos útiles

```bash
# Desde backend/
php artisan migrate
php artisan make:controller NombreController --api
php artisan make:model Nombre -mf
php artisan make:request Nombre/NombreStoreRequest
php artisan test
php artisan route:list --path=api
```
