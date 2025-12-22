# API de Campañas de Email

Documentación del API REST para crear y gestionar campañas de email (con tracking de aperturas y reenvíos).

## Información General

- **Base URL**: `/api/email-campaigns`
- **Autenticación**: Requerida (Sanctum Token), excepto tracking pixel
- **Formato**: JSON (y `multipart/form-data` para adjuntos)

## Permisos

Los endpoints usan middleware `can:*`:

- Listar / ver detalle: `email campaign list`
- Crear: `email campaign create`
- Editar / clonar: `email campaign edit`
- Eliminar: `email campaign delete`
- Enviar: `email campaign send`
- Reenvíos y edición de emails de log: `email campaign resend`

## Endpoints

### 1) Listar campañas

`GET /api/email-campaigns`

Query opcional:
- `per_page` (int | `'all'`)
- `page`
- `search` (busca en `campaign_name` y `subject`)
- `sort_by` (`id`, `campaign_name`, `subject`, `status`, `created_at`, `updated_at`)
- `sort_direction` (`asc` | `desc`)

### 2) Crear campaña (borrador)

`POST /api/email-campaigns` (`multipart/form-data`)

Campos:
- `campaign_name` (string)
- `subject` (string)
- `email_field_type` (string)
- `body` (string HTML)
- `client_ids[]` (ids clientes)
- `custom_emails[]` (opcional)
- `attachments[]` (opcional archivos, max 10MB)

### 3) Ver campaña

`GET /api/email-campaigns/{id}`

Incluye logs y cliente asociado cuando aplica.

### 4) Actualizar campaña (solo draft)

`PUT /api/email-campaigns/{id}` (`multipart/form-data`)

Mismos campos que creación. Si se envían `attachments[]`, se agregan (append) a los existentes.

### 5) Eliminar campaña

`DELETE /api/email-campaigns/{id}`

### 6) Clonar campaña

`POST /api/email-campaigns/{id}/clone`

Crea una copia en estado `draft` y copia adjuntos existentes.

### 7) Enviar campaña

`POST /api/email-campaigns/{id}/send`

Pone el estado en `sending`, crea logs `pending/failed` y despacha jobs por destinatario.

### 8) Catálogo de campos email

`GET /api/email-campaigns/email-fields`

### 9) Clientes por campo email

`GET /api/email-campaigns/clients?email_field=email`

Retorna clientes que tienen ese campo con emails válidos (formato coma-separado).

### 10) Enviar email de prueba

`POST /api/email-campaigns/send-test`

Body JSON:
```json
{
  "test_email": "test@correo.com",
  "subject": "Asunto",
  "body": "<p>Hola</p>"
}
```

### 11) Reenviar un log

`POST /api/email-campaigns/{campaignId}/logs/{logId}/resend`

Body opcional:
```json
{
  "email_field_type": "email",
  "custom_email": "alguien@correo.com"
}
```

### 12) Reenviar personalizado (subject/body/attachments extra)

`POST /api/email-campaigns/{campaignId}/logs/{logId}/resend-custom` (`multipart/form-data`)

Campos:
- `subject` (string)
- `body` (string HTML)
- `email` (string, puede ser comma-separated)
- `additional_attachments[]` (opcional)

### 13) Actualizar email destino de un log

`PUT /api/email-campaigns/{campaignId}/logs/{logId}/email`

Body:
```json
{ "email": "nuevo@correo.com, otro@correo.com" }
```

### 14) Tracking pixel (público)

`GET /api/email-campaigns/track-open/{logId}`

Incrementa `open_count` y setea `opened_at` en el primer hit. Devuelve un GIF 1x1.

