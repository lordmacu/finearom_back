# API de Roles

Documentación completa del API REST para la gestión de roles y sus permisos.

## Información General

- **Base URL**: `/api/roles`
- **Autenticación**: Requerida (Sanctum Token)
- **Formato de respuesta**: JSON
- **Modelo**: Role (Spatie Laravel Permission)

## Campos de la Tabla Roles

| Campo | Tipo | Descripción | Requerido |
|-------|------|-------------|-----------|
| id | bigint | Identificador único | Auto |
| name | string(255) | Nombre del rol | Sí |
| guard_name | string(255) | Nombre del guard | No (default: 'web') |
| created_at | timestamp | Fecha de creación | Auto |
| updated_at | timestamp | Fecha de actualización | Auto |

## Relaciones

- **permissions**: Permisos asignados al rol (many-to-many)
- **users**: Usuarios que tienen este rol (many-to-many)

## Endpoints

### 1. Listar Roles

Obtiene la lista de roles con sus permisos, soporta paginación, búsqueda y ordenamiento.

**Endpoint**: `GET /api/roles`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
```

**Parámetros Query** (todos opcionales):

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| per_page | integer/string | 15 | Registros por página (usa 'all' para todos) |
| page | integer | 1 | Número de página |
| search | string | - | Buscar por nombre de rol |
| sort_by | string | id | Columna para ordenar: `id`, `name`, `guard_name`, `created_at`, `updated_at` |
| sort_direction | string | asc | Dirección: `asc` o `desc` |

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "admin",
      "guard_name": "web",
      "created_at": "2024-12-15T10:30:00.000000Z",
      "updated_at": "2024-12-15T10:30:00.000000Z",
      "permissions": [
        {
          "id": 1,
          "name": "permission list"
        },
        {
          "id": 2,
          "name": "permission create"
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 2,
    "per_page": 15,
    "to": 15,
    "total": 20
  },
  "links": {
    "first": "http://127.0.0.1:8000/api/roles?page=1",
    "last": "http://127.0.0.1:8000/api/roles?page=2",
    "prev": null,
    "next": "http://127.0.0.1:8000/api/roles?page=2"
  }
}
```

**Ejemplos de uso**:
```bash
# Todos los roles sin paginación
GET /api/roles?per_page=all

# Búsqueda con paginación
GET /api/roles?search=admin&per_page=10

# Ordenar por fecha de creación descendente
GET /api/roles?sort_by=created_at&sort_direction=desc

# Combinación completa
GET /api/roles?search=user&per_page=20&sort_by=name&sort_direction=asc&page=1
```

---

### 2. Crear Rol

Crea un nuevo rol con permisos opcionales.

**Endpoint**: `POST /api/roles`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

**Body**:
```json
{
  "name": "manager",
  "guard_name": "web",
  "permissions": [1, 2, 5, 8]
}
```

**Parámetros**:

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| name | string | Sí | Nombre del rol (único, máx. 255 caracteres) |
| guard_name | string | No | Guard name (default: 'web', máx. 255 caracteres) |
| permissions | array | No | IDs de permisos a asignar |
| permissions.* | integer | No | Debe existir en la tabla permissions |

**Respuesta Exitosa** (201 Created):
```json
{
  "success": true,
  "message": "Rol creado exitosamente",
  "data": {
    "id": 5,
    "name": "manager",
    "guard_name": "web",
    "created_at": "2024-12-15T10:35:00.000000Z",
    "updated_at": "2024-12-15T10:35:00.000000Z",
    "permissions": [
      {
        "id": 1,
        "name": "permission list"
      },
      {
        "id": 2,
        "name": "permission create"
      }
    ]
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

```json
{
  "message": "The selected permissions.0 is invalid.",
  "errors": {
    "permissions.0": [
      "The selected permissions.0 is invalid."
    ]
  }
}
```

---

### 3. Ver Rol

Obtiene la información detallada de un rol específico con sus permisos.

**Endpoint**: `GET /api/roles/{id}`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
```

**Parámetros de URL**:

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| id | integer | ID del rol |

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "admin",
    "guard_name": "web",
    "created_at": "2024-12-15T10:30:00.000000Z",
    "updated_at": "2024-12-15T10:30:00.000000Z",
    "permissions": [
      {
        "id": 1,
        "name": "permission list"
      },
      {
        "id": 2,
        "name": "permission create"
      },
      {
        "id": 3,
        "name": "permission edit"
      }
    ]
  }
}
```

**Error - No Encontrado** (404 Not Found):
```json
{
  "message": "No query results for model [App\\Models\\Role] 999"
}
```

---

### 4. Actualizar Rol

Actualiza la información de un rol y sus permisos.

**Endpoint**: `PUT /api/roles/{id}` o `PATCH /api/roles/{id}`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

**Parámetros de URL**:

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| id | integer | ID del rol a actualizar |

**Body**:
```json
{
  "name": "manager updated",
  "guard_name": "web",
  "permissions": [1, 2, 3, 5]
}
```

**Parámetros**:

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| name | string | Sí | Nombre del rol (único excepto el actual, máx. 255 caracteres) |
| guard_name | string | No | Guard name (máx. 255 caracteres) |
| permissions | array | No | IDs de permisos (reemplaza todos los permisos existentes) |
| permissions.* | integer | No | Debe existir en la tabla permissions |

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Rol actualizado exitosamente",
  "data": {
    "id": 5,
    "name": "manager updated",
    "guard_name": "web",
    "created_at": "2024-12-15T10:35:00.000000Z",
    "updated_at": "2024-12-15T10:40:00.000000Z",
    "permissions": [
      {
        "id": 1,
        "name": "permission list"
      },
      {
        "id": 2,
        "name": "permission create"
      },
      {
        "id": 3,
        "name": "permission edit"
      },
      {
        "id": 5,
        "name": "role list"
      }
    ]
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

---

### 5. Eliminar Rol

Elimina un rol del sistema. No permite eliminar si hay usuarios asignados al rol.

**Endpoint**: `DELETE /api/roles/{id}`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
```

**Parámetros de URL**:

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| id | integer | ID del rol a eliminar |

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Rol eliminado exitosamente"
}
```

**Error - Rol con Usuarios Asignados** (422 Unprocessable Entity):
```json
{
  "success": false,
  "message": "No se puede eliminar el rol porque tiene 5 usuario(s) asignado(s)"
}
```

**Error - No Encontrado** (404 Not Found):
```json
{
  "message": "No query results for model [App\\Models\\Role] 999"
}
```

---

### 6. Obtener Todos los Permisos

Obtiene la lista completa de permisos disponibles para asignar a roles.

**Endpoint**: `GET /api/roles/permissions`

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
      "name": "permission list"
    },
    {
      "id": 2,
      "name": "permission create"
    },
    {
      "id": 3,
      "name": "permission edit"
    },
    {
      "id": 4,
      "name": "permission delete"
    }
  ]
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
| 422 | Unprocessable Entity - Error de validación o rol con usuarios asignados |
| 500 | Internal Server Error - Error del servidor |

---

## Reglas de Validación

### Crear Rol

- **name**: 
  - Requerido
  - Tipo: string
  - Máximo: 255 caracteres
  - Único en la tabla roles
  
- **guard_name**: 
  - Opcional (default: 'web')
  - Tipo: string
  - Máximo: 255 caracteres

- **permissions**:
  - Opcional
  - Tipo: array
  - Cada elemento debe ser un ID válido de la tabla permissions

### Actualizar Rol

- **name**: 
  - Requerido
  - Tipo: string
  - Máximo: 255 caracteres
  - Único en la tabla roles (excepto el registro actual)
  
- **guard_name**: 
  - Opcional
  - Tipo: string
  - Máximo: 255 caracteres

- **permissions**:
  - Opcional
  - Tipo: array
  - Cada elemento debe ser un ID válido de la tabla permissions
  - **Importante**: Reemplaza todos los permisos existentes

---

## Ejemplos de Uso con cURL

### Listar todos los roles
```bash
curl -X GET http://127.0.0.1:8000/api/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Listar roles con búsqueda y ordenamiento
```bash
curl -X GET "http://127.0.0.1:8000/api/roles?search=admin&sort_by=created_at&sort_direction=desc&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Crear un nuevo rol con permisos
```bash
curl -X POST http://127.0.0.1:8000/api/roles \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "editor",
    "guard_name": "web",
    "permissions": [1, 2, 5, 8, 9]
  }'
```

### Ver un rol específico
```bash
curl -X GET http://127.0.0.1:8000/api/roles/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Actualizar un rol
```bash
curl -X PUT http://127.0.0.1:8000/api/roles/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "editor updated",
    "permissions": [1, 2, 3, 5, 8, 9, 10]
  }'
```

### Eliminar un rol
```bash
curl -X DELETE http://127.0.0.1:8000/api/roles/1 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

### Obtener todos los permisos disponibles
```bash
curl -X GET http://127.0.0.1:8000/api/roles/permissions \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"
```

---

## Ejemplos de Uso con JavaScript (Axios)

```javascript
import axios from 'axios';

const api = axios.create({
  baseURL: 'http://127.0.0.1:8000/api',
  headers: {
    'Accept': 'application/json',
    'Authorization': `Bearer ${token}`
  }
});

// Listar roles con paginación
const getRoles = async (params = {}) => {
  try {
    const response = await api.get('/roles', { params });
    console.log(response.data);
    // params: { per_page: 10, page: 1, search: 'admin', sort_by: 'name', sort_direction: 'asc' }
  } catch (error) {
    console.error(error.response.data);
  }
};

// Crear rol con permisos
const createRole = async (data) => {
  try {
    const response = await api.post('/roles', {
      name: 'manager',
      guard_name: 'web',
      permissions: [1, 2, 5, 8]
    });
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};

// Ver rol
const getRole = async (id) => {
  try {
    const response = await api.get(`/roles/${id}`);
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};

// Actualizar rol
const updateRole = async (id, data) => {
  try {
    const response = await api.put(`/roles/${id}`, {
      name: 'manager updated',
      permissions: [1, 2, 3, 5]
    });
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};

// Eliminar rol
const deleteRole = async (id) => {
  try {
    const response = await api.delete(`/roles/${id}`);
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};

// Obtener permisos disponibles
const getAvailablePermissions = async () => {
  try {
    const response = await api.get('/roles/permissions');
    console.log(response.data);
  } catch (error) {
    console.error(error.response.data);
  }
};
```

---

## Notas Importantes

1. **Autenticación**: Todos los endpoints requieren un token de autenticación Sanctum válido.

2. **Guard Name**: Si no se especifica, el valor por defecto es 'web'.

3. **Permisos del Sistema**: 
   - Al crear/actualizar un rol, se utiliza `syncPermissions()` de Spatie
   - Esto significa que los permisos enviados **reemplazan** todos los existentes
   - Si no se envía el array `permissions`, los permisos actuales se mantienen

4. **Protección contra eliminación**:
   - No se puede eliminar un rol que tiene usuarios asignados
   - Se devuelve un error 422 con el número de usuarios asignados

5. **Relaciones cargadas**:
   - `index()` y `show()` cargan automáticamente los permisos del rol
   - Solo se cargan `id` y `name` de los permisos para optimizar la respuesta

6. **Ordenamiento por defecto**: Por `id` ascendente

7. **Columnas permitidas para ordenar**: `id`, `name`, `guard_name`, `created_at`, `updated_at`

8. **Paginación**:
   - Default: 15 registros por página
   - Usa `per_page=all` para obtener todos sin paginación

---

## Flujo de Trabajo Típico

### 1. Crear un nuevo rol con permisos

```javascript
// Paso 1: Obtener permisos disponibles
const permissionsResponse = await api.get('/roles/permissions');
const allPermissions = permissionsResponse.data.data;

// Paso 2: Seleccionar permisos que necesita el rol
const selectedPermissionIds = [1, 2, 5, 8, 9, 10];

// Paso 3: Crear el rol
const roleResponse = await api.post('/roles', {
  name: 'Content Manager',
  permissions: selectedPermissionIds
});

console.log('Rol creado:', roleResponse.data.data);
```

### 2. Editar permisos de un rol existente

```javascript
// Paso 1: Obtener el rol actual
const roleResponse = await api.get('/roles/5');
const currentRole = roleResponse.data.data;

// Paso 2: Modificar permisos (agregar nuevos)
const currentPermissionIds = currentRole.permissions.map(p => p.id);
const newPermissionIds = [...currentPermissionIds, 15, 16, 17];

// Paso 3: Actualizar el rol
const updateResponse = await api.put('/roles/5', {
  name: currentRole.name,
  permissions: newPermissionIds
});

console.log('Rol actualizado:', updateResponse.data.data);
```

---

## Solución de Problemas

### Error 401 Unauthorized
- Verifica que el token de autenticación sea válido
- Asegúrate de incluir el header `Authorization: Bearer {token}`

### Error 422 Validation Error
- Revisa que los campos requeridos estén presentes
- Verifica que el campo `name` no esté duplicado
- Asegúrate de que los IDs de permisos existan en la base de datos

### Error 422 al eliminar rol
- El rol tiene usuarios asignados
- Primero debes reasignar o eliminar los usuarios antes de eliminar el rol

### Error 404 Not Found
- Verifica que el ID del rol exista en la base de datos
- Asegúrate de usar la URL correcta

### Error 500 Internal Server Error
- Revisa los logs de Laravel en `storage/logs/laravel.log`
- Verifica la conexión a la base de datos
- Asegúrate de que el paquete Spatie Permission esté instalado correctamente

---

## Estructura del Proyecto

```
backend/
├── app/
│   ├── Models/
│   │   ├── Permission.php
│   │   └── Role.php
│   └── Http/
│       └── Controllers/
│           ├── PermissionController.php
│           └── RoleController.php
└── routes/
    └── api.php
```

---

## Testing

### Colección Postman/Insomnia

**Variables de entorno**:
- `base_url`: `http://127.0.0.1:8000/api`
- `token`: Tu token de autenticación

**Secuencia de pruebas**:

1. Login para obtener token
2. GET `/roles/permissions` - Ver permisos disponibles
3. POST `/roles` - Crear rol con permisos
4. GET `/roles` - Listar todos los roles
5. GET `/roles/{id}` - Ver rol específico
6. PUT `/roles/{id}` - Actualizar rol
7. DELETE `/roles/{id}` - Eliminar rol
