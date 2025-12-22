# API de Cuenta (Perfil y Password)

Documentación para consultar/actualizar el perfil del usuario autenticado y cambiar contraseña.

## Información General

- **Base URL**: `/api/account`
- **Autenticación**: Requerida (Sanctum Token)
- **Formato de respuesta**: JSON

## Headers comunes

```http
Authorization: Bearer {token}
Accept: application/json
```

## Endpoints

### 1. Obtener Cuenta (usuario autenticado)

**Endpoint**: `GET /api/account`

Incluye roles (`roles: [ {id, name} ]`).

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Admin",
    "email": "admin@test.com",
    "roles": [
      { "id": 1, "name": "admin" }
    ]
  }
}
```

---

### 2. Actualizar Cuenta (nombre/email)

**Endpoint**: `PUT /api/account`

**Headers**:
```http
Authorization: Bearer {token}
Accept: application/json
Content-Type: application/json
```

**Body**:
```json
{
  "name": "Nuevo Nombre",
  "email": "nuevo@email.com"
}
```

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Cuenta actualizada exitosamente",
  "data": {
    "id": 1,
    "name": "Nuevo Nombre",
    "email": "nuevo@email.com",
    "roles": [
      { "id": 1, "name": "admin" }
    ]
  }
}
```

---

### 3. Cambiar Contraseña

**Endpoint**: `PUT /api/account/password`

**Body**:
```json
{
  "old_password": "PasswordActual123!",
  "new_password": "PasswordNuevo123!",
  "confirm_password": "PasswordNuevo123!"
}
```

Reglas:
- `confirm_password` debe ser igual a `new_password`.
- `old_password` debe coincidir con la contraseña actual (si no, retorna error de validación).

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Contraseña actualizada exitosamente"
}
```

**Error de validación (422) si `old_password` es incorrecta**:
```json
{
  "message": "The old_password field is invalid.",
  "errors": {
    "old_password": [
      "Old password is incorrect."
    ]
  }
}
```

## Errores comunes

- **401 Unauthorized**: no hay token válido (falta `Authorization: Bearer`).
- **422 Unprocessable Entity**: validación (email duplicado, confirmación no coincide, contraseña actual incorrecta).

