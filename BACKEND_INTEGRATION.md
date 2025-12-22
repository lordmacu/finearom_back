# 🔗 Integración Frontend - Backend

## Configuración CORS en Laravel

Para permitir que el frontend (puerto 3000) se comunique con el backend (puerto 80), necesitas configurar CORS en Laravel.

### 1. Actualizar `config/cors.php`

```php
<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    
    'allowed_methods' => ['*'],
    
    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],
    
    'allowed_origins_patterns' => [],
    
    'allowed_headers' => ['*'],
    
    'exposed_headers' => [],
    
    'max_age' => 0,
    
    'supports_credentials' => true,
];
```

### 2. Verificar Middleware

En `app/Http/Kernel.php`, asegúrate de que el middleware CORS esté en el grupo 'api':

```php
protected $middlewareGroups = [
    'api' => [
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Fruitcake\Cors\HandleCors::class, // CORS middleware
    ],
];
```

## API Routes en Laravel

### Ejemplo de rutas de autenticación (`routes/api.php`)

```php
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// Autenticación
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Rutas protegidas
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    
    Route::post('/logout', [AuthController::class, 'logout']);
    
    // Más rutas protegidas...
});
```

## Controlador de Autenticación

### `app/Http/Controllers/AuthController.php`

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Credenciales inválidas'
            ], 401);
        }

        $user = Auth::user();
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'Login exitoso'
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        
        return response()->json([
            'message' => 'Logout exitoso'
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
            'message' => 'Usuario creado exitosamente'
        ], 201);
    }
}
```

## Configurar Laravel Sanctum

### 1. Instalar (si no está instalado)

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

### 2. Configurar `config/sanctum.php`

```php
'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
    '%s%s',
    'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
    Sanctum::currentApplicationUrlWithPort()
))),
```

### 3. Agregar middleware en `app/Http/Kernel.php`

```php
'api' => [
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    'throttle:api',
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
],
```

## Ejemplo CRUD Completo

### Backend - `app/Http/Controllers/ProductController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::paginate(10);
        return response()->json($products);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $product = Product::create($validated);

        return response()->json([
            'data' => $product,
            'message' => 'Producto creado exitosamente'
        ], 201);
    }

    public function show($id)
    {
        $product = Product::findOrFail($id);
        return response()->json($product);
    }

    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'string|max:255',
            'price' => 'numeric|min:0',
            'description' => 'nullable|string',
        ]);

        $product->update($validated);

        return response()->json([
            'data' => $product,
            'message' => 'Producto actualizado exitosamente'
        ]);
    }

    public function destroy($id)
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json([
            'message' => 'Producto eliminado exitosamente'
        ]);
    }
}
```

### Frontend - `src/services/productService.js`

```javascript
import api from './api'

export const productService = {
  async getAll(page = 1) {
    const response = await api.get(`/products?page=${page}`)
    return response.data
  },

  async getById(id) {
    const response = await api.get(`/products/${id}`)
    return response.data
  },

  async create(productData) {
    const response = await api.post('/products', productData)
    return response.data
  },

  async update(id, productData) {
    const response = await api.put(`/products/${id}`, productData)
    return response.data
  },

  async delete(id) {
    const response = await api.delete(`/products/${id}`)
    return response.data
  }
}
```


## Manejo de Errores Global

### Backend - `app/Exceptions/Handler.php`

```php
public function render($request, Throwable $exception)
{
    if ($request->expectsJson()) {
        if ($exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'message' => 'Recurso no encontrado'
            ], 404);
        }

        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $exception->errors()
            ], 422);
        }

        return response()->json([
            'message' => $exception->getMessage()
        ], 500);
    }

    return parent::render($request, $exception);
}
```

## Testing

### Probar endpoints con curl

```bash
# Login
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'

# Get productos (con token)
curl -X GET http://localhost/api/products \
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

---

**Última actualización:** 15 de Diciembre, 2025
