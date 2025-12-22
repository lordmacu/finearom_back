# API de Usuarios

Documentación del API REST para la gestión de usuarios (CRUD) y asignación de roles.

## Información General

- **Base URL**: `/api/users`
- **Autenticación**: Requerida (Sanctum Token)
- **Formato de respuesta**: JSON
- **Modelo**: User (Laravel) + Roles (Spatie Laravel Permission)

## Permisos requeridos (por endpoint)

Estos endpoints usan middleware `can:*`:

- `GET /api/users` y `GET /api/users/{id}`: **`user list`**
- `POST /api/users`: **`user create`**
- `PUT/PATCH /api/users/{id}`: **`user edit`**
- `DELETE /api/users/{id}`: **`user delete`**

## Headers comunes

```http
Authorization: Bearer {token}
Accept: application/json
```

## Campos principales

| Campo | Tipo | Descripción | Requerido |
|------|------|-------------|----------|
| id | bigint | Identificador único | Auto |
| name | string | Nombre | Sí |
| email | string | Email | Sí |
| password | string | Password en texto plano (se hashea) | Solo create / opcional update |
| roles | array<int> | IDs de roles a asignar | No |

## Endpoints

### 1. Listar Usuarios

Soporta paginación, búsqueda y ordenamiento. Incluye roles (`roles: [ {id, name} ]`).

**Endpoint**: `GET /api/users`

**Parámetros Query** (todos opcionales):

| Parámetro | Tipo | Default | Descripción |
|----------|------|---------|-------------|
| per_page | integer/string | 15 | Registros por página (usa `'all'` para todos) |
| page | integer | 1 | Número de página |
| search | string | - | Busca por `name` o `email` |
| sort_by | string | id | `id`, `name`, `email`, `email_verified_at`, `created_at`, `updated_at` |
| sort_direction | string | asc | `asc` o `desc` |

**Respuesta Exitosa** (200 OK - paginado):
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Admin",
      "email": "admin@test.com",
      "email_verified_at": null,
      "created_at": "2024-12-15T10:30:00.000000Z",
      "updated_at": "2024-12-15T10:30:00.000000Z",
      "roles": [
        { "id": 1, "name": "admin" }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 15,
    "to": 1,
    "total": 1
  },
  "links": {
    "first": "http://127.0.0.1:8000/api/users?page=1",
    "last": "http://127.0.0.1:8000/api/users?page=1",
    "prev": null,
    "next": null
  }
}
```

**Ejemplos**:
```bash
# Paginación
GET /api/users?per_page=10&page=1

# Buscar
GET /api/users?search=gmail

# Ordenar
GET /api/users?sort_by=created_at&sort_direction=desc

# Todos sin paginación
GET /api/users?per_page=all
```

---

### 2. Crear Usuario

**Endpoint**: `POST /api/users`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

**Body**:
```json
{
  "name": "Juan Pérez",
  "email": "juan@correo.com",
  "password": "PasswordSeguro123!",
  "roles": [1, 2]
}
```

**Respuesta Exitosa** (201 Created):
```json
{
  "success": true,
  "message": "Usuario creado exitosamente",
  "data": {
    "id": 10,
    "name": "Juan Pérez",
    "email": "juan@correo.com",
    "roles": [
      { "id": 1, "name": "admin" }
    ]
  }
}
```

---

### 3. Ver Usuario

**Endpoint**: `GET /api/users/{id}`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": {
    "id": 10,
    "name": "Juan Pérez",
    "email": "juan@correo.com",
    "roles": [
      { "id": 1, "name": "admin" }
    ]
  }
}
```

---

### 4. Actualizar Usuario

**Endpoint**: `PUT /api/users/{id}` (o `PATCH`)

**Body** (ejemplo):
```json
{
  "name": "Juan Pérez",
  "email": "juan@correo.com",
  "password": "NuevoPassword123!",
  "roles": [2]
}
```

Notas:
- `password` es **opcional** (si no se envía, no se cambia).
- Si envías `roles`, se hace `sync` de roles (reemplaza los roles anteriores por los enviados).

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Usuario actualizado exitosamente",
  "data": {
    "id": 10,
    "name": "Juan Pérez",
    "email": "juan@correo.com",
    "roles": [
      { "id": 2, "name": "user" }
    ]
  }
}
```

---

### 5. Eliminar Usuario

**Endpoint**: `DELETE /api/users/{id}`

Regla adicional:
- No permite eliminar tu propio usuario (retorna 422).

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Usuario eliminado exitosamente"
}
```

**Error (422) si intentas eliminarte a ti mismo**:
```json
{
  "success": false,
  "message": "No puedes eliminar tu propio usuario."
}
```

## Errores comunes

- **401 Unauthorized**: no hay token válido (falta `Authorization: Bearer`).
- **403 Forbidden**: autenticado, pero sin el permiso `user *` requerido.
- **422 Unprocessable Entity**: validación (email duplicado, password débil, roles inexistentes, etc.).

