# API de Permisos

Documentación completa del API REST para la gestión de permisos.

## Información General

- **Base URL**: `/api/permissions`
- **Autenticación**: Requerida (Sanctum Token)
- **Formato de respuesta**: JSON
- **Modelo**: Permission (Spatie Laravel Permission)

## Campos de la Tabla Permissions

| Campo | Tipo | Descripción | Requerido |
|-------|------|-------------|-----------|
| id | bigint | Identificador único | Auto |
| name | string(255) | Nombre del permiso | Sí |
| guard_name | string(255) | Nombre del guard | No (default: 'web') |
| created_at | timestamp | Fecha de creación | Auto |
| updated_at | timestamp | Fecha de actualización | Auto |

## Endpoints

### 1. Listar Permisos

Obtiene la lista completa de permisos ordenados alfabéticamente por nombre.

**Endpoint**: `GET /api/permissions`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
```

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "permission list",
      "guard_name": "web",
      "created_at": "2024-12-15T10:30:00.000000Z",
      "updated_at": "2024-12-15T10:30:00.000000Z"
    },
    {
      "id": 2,
      "name": "permission create",
      "guard_name": "web",
      "created_at": "2024-12-15T10:30:00.000000Z",
      "updated_at": "2024-12-15T10:30:00.000000Z"
    }
  ]
}
```

---

### 2. Crear Permiso

Crea un nuevo permiso en el sistema.

**Endpoint**: `POST /api/permissions`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

**Body**:
```json
{
  "name": "user edit",
  "guard_name": "web"
}
```

**Parámetros**:

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| name | string | Sí | Nombre del permiso (único, máx. 255 caracteres) |
| guard_name | string | No | Guard name (default: 'web', máx. 255 caracteres) |

**Respuesta Exitosa** (201 Created):
```json
{
  "success": true,
  "message": "Permiso creado exitosamente",
  "data": {
    "id": 3,
    "name": "user edit",
    "guard_name": "web",
    "created_at": "2024-12-15T10:35:00.000000Z",
    "updated_at": "2024-12-15T10:35:00.000000Z"
  }
}
```

**Errores de Validación** (422 Unprocessable Entity):
```json
{
  "message": "The name field is required. (and 1 more error)",
  "errors": {
    "name": [
      "The name field is required."
    ]
  }
}
```

```json
{
  "message": "The name has already been taken.",
  "errors": {
    "name": [
      "The name has already been taken."
    ]
  }
}
```

---

### 3. Ver Permiso

Obtiene la información detallada de un permiso específico.

**Endpoint**: `GET /api/permissions/{id}`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
```

**Parámetros de URL**:

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| id | integer | ID del permiso |

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "permission list",
    "guard_name": "web",
    "created_at": "2024-12-15T10:30:00.000000Z",
    "updated_at": "2024-12-15T10:30:00.000000Z"
  }
}
```

**Error - No Encontrado** (404 Not Found):
```json
{
  "message": "No query results for model [App\\Models\\Permission] 999"
}
```

---

### 4. Actualizar Permiso

Actualiza la información de un permiso existente.

**Endpoint**: `PUT /api/permissions/{id}` o `PATCH /api/permissions/{id}`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

**Parámetros de URL**:

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| id | integer | ID del permiso a actualizar |

**Body**:
```json
{
  "name": "user edit updated",
  "guard_name": "web"
}
```

**Parámetros**:

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| name | string | Sí | Nombre del permiso (único excepto el actual, máx. 255 caracteres) |
| guard_name | string | No | Guard name (máx. 255 caracteres) |

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Permiso actualizado exitosamente",
  "data": {
    "id": 3,
    "name": "user edit updated",
    "guard_name": "web",
    "created_at": "2024-12-15T10:35:00.000000Z",
    "updated_at": "2024-12-15T10:40:00.000000Z"
  }
}
```

**Errores de Validación** (422 Unprocessable Entity):
```json
{
  "message": "The name has already been taken.",
  "errors": {
    "name": [
      "The name has already been taken."
    ]
  }
}
```

**Error - No Encontrado** (404 Not Found):
```json
{
  "message": "No query results for model [App\\Models\\Permission] 999"
}
```

---

### 5. Eliminar Permiso

Elimina un permiso del sistema.

**Endpoint**: `DELETE /api/permissions/{id}`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
```

**Parámetros de URL**:

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| id | integer | ID del permiso a eliminar |

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Permiso eliminado exitosamente"
}
```

**Error - No Encontrado** (404 Not Found):
```json
{
  "message": "No query results for model [App\\Models\\Permission] 999"
}
```

---

## Códigos de Estado HTTP

| Código | Descripción |
|--------|-------------|
| 200 | OK - Solicitud exitosa |
| 201 | Created - Recurso creado exitosamente |
| 401 | Unauthorized - Token no válido o faltante |
| 404 | Not Found - Recurso no encontrado |
| 422 | Unprocessable Entity - Error de validación |
| 500 | Internal Server Error - Error del servidor |

---

## Reglas de Validación

### Crear Permiso

- **name**: 
  - Requerido
  - Tipo: string
  - Máximo: 255 caracteres
  - Único en la tabla permissions
  
- **guard_name**: 
  - Opcional (default: 'web')
  - Tipo: string
  - Máximo: 255 caracteres

### Actualizar Permiso

- **name**: 
  - Requerido
  - Tipo: string
  - Máximo: 255 caracteres
  - Único en la tabla permissions (excepto el registro actual)
  
- **guard_name**: 
  - Opcional
  - Tipo: string
  - Máximo: 255 caracteres

---

## Ejemplos de Uso con cURL

### Listar todos los permisos
```bash
curl -X GET http://localhost:8000/api/permissions \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Crear un nuevo permiso
```bash
curl -X POST http://localhost:8000/api/permissions \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "post create",
    "guard_name": "web"
  }'
```

### Ver un permiso específico
```bash
curl -X GET http://localhost:8000/api/permissions/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Actualizar un permiso
```bash
curl -X PUT http://localhost:8000/api/permissions/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "post edit",
    "guard_name": "web"
  }'
```

### Eliminar un permiso
```bash
curl -X DELETE http://localhost:8000/api/permissions/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

## Ejemplos de Uso con JavaScript (Axios)

```javascript
import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8000/api',
  headers: {
    'Accept': 'application/json',
    'Authorization': `Bearer ${token}`
  }
});

// Listar permisos
const getPermissions = async () => {
  try {
    const response = await api.get('/permissions');
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};

// Crear permiso
const createPermission = async (data) => {
  try {
    const response = await api.post('/permissions', data);
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};

// Ver permiso
const getPermission = async (id) => {
  try {
    const response = await api.get(`/permissions/${id}`);
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};

// Actualizar permiso
const updatePermission = async (id, data) => {
  try {
    const response = await api.put(`/permissions/${id}`, data);
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};

// Eliminar permiso
const deletePermission = async (id) => {
  try {
    const response = await api.delete(`/permissions/${id}`);
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};
```

---

## Notas Importantes

1. **Autenticación**: Todos los endpoints requieren un token de autenticación Sanctum válido.

2. **Guard Name**: Si no se especifica, el valor por defecto es 'web'. Los guards comunes son:
   - `web`: Para autenticación basada en sesión
   - `api`: Para autenticación de API

3. **Permisos del Sistema**: Este API está basado en el paquete Spatie Laravel Permission. Los permisos se pueden asignar a roles y usuarios.

4. **Unicidad**: El campo `name` debe ser único considerando también el `guard_name`.

5. **Relaciones**: Los permisos pueden estar relacionados con:
   - Roles (tabla `role_has_permissions`)
   - Usuarios (tabla `model_has_permissions`)

6. **Caché**: Spatie Permission utiliza caché. Si realizas cambios directos en la base de datos, ejecuta:
   ```bash
   php artisan permission:cache-reset
   ```

---

## Estructura del Proyecto

```
backend/
├── app/
│   ├── Models/
│   │   └── Permission.php
│   └── Http/
│       └── Controllers/
│           └── PermissionController.php
└── routes/
    └── api.php
```

---

## Testing

### Probar los endpoints

Puedes probar estos endpoints utilizando:
- Postman
- Insomnia
- cURL
- Thunder Client (VS Code Extension)

### Ejemplo de colección para testing

1. Configurar variable de entorno `{{base_url}}` = `http://localhost:8000/api`
2. Configurar variable de entorno `{{token}}` con tu token de autenticación
3. Importar los endpoints mencionados arriba

---

## Solución de Problemas

### Error 401 Unauthorized
- Verifica que el token de autenticación sea válido
- Asegúrate de incluir el header `Authorization: Bearer {token}`

### Error 422 Validation Error
- Revisa que los campos requeridos estén presentes
- Verifica que el campo `name` no esté duplicado

### Error 404 Not Found
- Verifica que el ID del permiso exista en la base de datos
- Asegúrate de usar la URL correcta

### Error 500 Internal Server Error
- Revisa los logs de Laravel en `storage/logs/laravel.log`
- Verifica la conexión a la base de datos
- Asegúrate de que el paquete Spatie Permission esté instalado correctamente
