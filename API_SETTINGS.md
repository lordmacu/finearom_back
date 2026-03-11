# API de Configuración (Admin)

Documentación del API REST para la configuración administrativa del sistema:

- Configuración de destinatarios por proceso (tabla `processes`)
- Template HTML del email de pedido (tabla `config_systems`, key `templatePedido`)
- Backups de base de datos (crear/listar/restaurar)
- Márgenes de contribución por tipo de cliente y volumen (tabla `contribution_margins`)

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
- `GET|POST|PUT|DELETE /api/contribution-margins`: **`settings manage`**
- `GET /api/contribution-margins/lookup`: **`project list`**

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

---

## 7) Listar Márgenes de Contribución

Retorna todos los márgenes agrupados por `tipo_cliente`, ordenados por `volumen_min`.

**Endpoint**: `GET /api/contribution-margins`

**Permiso**: `settings manage`

**Respuesta Exitosa** (200 OK):
```json
{
  "data": {
    "pareto": [
      { "id": 1, "tipo_cliente": "pareto", "volumen_min": 0,   "volumen_max": 49,  "factor": 1.6, "descripcion": "Pareto — 0 a 49 Kg/año",           "activo": true },
      { "id": 2, "tipo_cliente": "pareto", "volumen_min": 50,  "volumen_max": 199, "factor": 1.5, "descripcion": "Pareto — 50 a 199 Kg/año",          "activo": true },
      { "id": 3, "tipo_cliente": "pareto", "volumen_min": 200, "volumen_max": null,"factor": 1.4, "descripcion": "Pareto — 200 Kg/año en adelante",   "activo": true }
    ],
    "balance": [ "..." ],
    "none":    [ "..." ]
  }
}
```

---

## 8) Crear Margen de Contribución

**Endpoint**: `POST /api/contribution-margins`

**Permiso**: `settings manage`

**Body**:
```json
{
  "tipo_cliente": "pareto",
  "volumen_min":  300,
  "volumen_max":  null,
  "factor":       1.35,
  "descripcion":  "Pareto premium 300+",
  "activo":       true
}
```

**Validaciones**:
- `tipo_cliente`: requerido, enum `pareto|balance|none`
- `volumen_min`: requerido, integer, min:0
- `volumen_max`: nullable, integer, min:0, debe ser mayor que `volumen_min` si se envía
- `factor`: requerido, numeric, min:0.1, max:99
- `descripcion`: nullable, string
- `activo`: boolean

**Respuesta Exitosa** (201 Created):
```json
{
  "data": { "id": 10, "tipo_cliente": "pareto", "volumen_min": 300, "volumen_max": null, "factor": 1.35, "descripcion": "Pareto premium 300+", "activo": true },
  "message": "Margen creado"
}
```

---

## 9) Actualizar Margen de Contribución

**Endpoint**: `PUT /api/contribution-margins/{id}`

**Permiso**: `settings manage`

**Body** (todos los campos son opcionales en update):
```json
{
  "factor": 1.3,
  "activo": false
}
```

**Respuesta Exitosa** (200 OK):
```json
{
  "data": { "id": 10, "factor": 1.3, "activo": false, "..." : "..." },
  "message": "Margen actualizado"
}
```

---

## 10) Eliminar Margen de Contribución

**Endpoint**: `DELETE /api/contribution-margins/{id}`

**Permiso**: `settings manage`

**Respuesta Exitosa** (200 OK):
```json
{
  "message": "Margen eliminado"
}
```

---

## 11) Consultar Factor por Tipo Cliente y Volumen (lookup)

Usado por el frontend de proyectos para auto-rellenar el campo `factor` al crear/editar un proyecto.

**Endpoint**: `GET /api/contribution-margins/lookup`

**Permiso**: `project list`

**Query params**:
| Param         | Tipo    | Descripción                          |
|---------------|---------|--------------------------------------|
| `tipo_cliente`| string  | `pareto`, `balance` o `none`         |
| `volumen`     | integer | Volumen en Kg/año (>= 0)             |

**Ejemplo**: `GET /api/contribution-margins/lookup?tipo_cliente=pareto&volumen=100`

**Respuesta Exitosa** (200 OK):
```json
{ "factor": 1.5 }
```

**Respuesta cuando no hay rango configurado**:
```json
{ "factor": null }
```

---

## Errores comunes

- **401 Unauthorized**: falta token o es inválido.
- **403 Forbidden**: no tienes el permiso requerido.
- **422 Unprocessable Entity**: validación fallida (tipo_cliente inválido, factor fuera de rango, etc.).
- **500**: fallo en `mysqldump`/`mysql` (revisar que estén en PATH del servidor).
