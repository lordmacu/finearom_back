# API de Configuración (Admin)

Documentación del API REST para la configuración administrativa del sistema:

- Configuración de destinatarios por proceso (tabla `processes`)
- Template HTML del email de pedido (tabla `config_systems`, key `templatePedido`)
- Backups de base de datos (crear/listar/restaurar)

## Información General

- **Base URL**: `/api/settings`
- **Autenticación**: Requerida (Sanctum Token)
- **Formato de respuesta**: JSON

## Permisos requeridos (por endpoint)

Estos endpoints usan middleware `can:*`:

- `GET /api/settings/admin-configuration` y `GET /api/settings/backups`: **`config view`**
- `PUT /api/settings/processes` y `PUT /api/settings/template-pedido`: **`config edit`**
- `POST /api/settings/backups`: **`backup create`**
- `POST /api/settings/backups/restore`: **`backup restore`**

## Headers comunes

```http
Authorization: Bearer {token}
Accept: application/json
```

---

## 1) Obtener Configuración Administrativa

Devuelve en una sola llamada: procesos, template de pedido y lista de backups.

**Endpoint**: `GET /api/settings/admin-configuration`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": {
    "processes": [
      {
        "id": 1,
        "name": "Operaciones",
        "email": "ops@empresa.com",
        "process_type": "orden_de_compra",
        "created_at": "2024-12-15T10:30:00.000000Z",
        "updated_at": "2024-12-15T10:30:00.000000Z"
      }
    ],
    "template_pedido": "<p>Hola...</p>",
    "backups": [
      "backup_2025-12-18_08-10-00.sql"
    ]
  }
}
```

---

## 2) Actualizar Procesos (destinatarios por proceso)

Reemplaza completamente la configuración de `processes` con el arreglo enviado (se hace delete+insert en transacción).

**Endpoint**: `PUT /api/settings/processes`

**Body**:
```json
{
  "rows": [
    { "name": "Operaciones", "email": "ops@empresa.com", "process_type": "orden_de_compra" },
    { "name": "Logística", "email": "log@empresa.com", "process_type": "confirmacion_despacho" }
  ]
}
```

**process_type permitido**:
- `orden_de_compra`
- `confirmacion_despacho`
- `pedido`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Configuración de procesos actualizada"
}
```

---

## 3) Actualizar Template de Pedido

Actualiza el HTML (string) guardado en `config_system` con `key=templatePedido`.
En base de datos la tabla usada es `config_systems`.

**Endpoint**: `PUT /api/settings/template-pedido`

**Body**:
```json
{
  "template_pedido": "<h1>Pedido</h1><p>Contenido...</p>"
}
```

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Template de pedido actualizado"
}
```

---

## 4) Listar Backups

Lista archivos `.sql` existentes en `storage/app/backups`.

**Endpoint**: `GET /api/settings/backups`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": [
    "backup_2025-12-18_08-10-00.sql",
    "backup_2025-12-17_17-30-12.sql"
  ]
}
```

---

## 5) Crear Backup

Ejecuta `mysqldump` en el servidor y guarda un `.sql` en `storage/app/backups`.

**Endpoint**: `POST /api/settings/backups`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Backup creado correctamente",
  "data": {
    "file": "backup_2025-12-18_08-10-00.sql",
    "backups": [
      "backup_2025-12-18_08-10-00.sql"
    ]
  }
}
```

---

## 6) Restaurar Backup

Restaura la base de datos desde el archivo seleccionado.

**Endpoint**: `POST /api/settings/backups/restore`

**Body**:
```json
{
  "backup": "backup_2025-12-18_08-10-00.sql"
}
```

Validación de seguridad:
- Solo permite nombres que cumplan `^[A-Za-z0-9._-]+\\.sql$` (evita path traversal).

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "La base de datos ha sido restaurada exitosamente."
}
```

## Errores comunes

- **401 Unauthorized**: falta token o es inválido.
- **403 Forbidden**: no tienes el permiso `config *` / `backup *`.
- **422 Unprocessable Entity**: validación (rows mal formadas, process_type inválido, etc.).
- **500**: fallo en `mysqldump`/`mysql` (revisar que estén en PATH del servidor).
