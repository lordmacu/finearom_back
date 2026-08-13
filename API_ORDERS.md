# API — Órdenes de Compra

## Flujo de roles

| Persona | Acción | Endpoint |
|---------|--------|----------|
| **Francy** | 1. Crea la orden | `POST /purchase-orders` |
| **Marlon** | 2. Agrega observaciones y fecha estimada de despacho | `POST /purchase-orders/{id}/observations` |
| **Alexa** | 3. Confirma despacho real (fecha, factura, guía) | `POST /purchase-orders/{id}/update-status` |

---

## Endpoints

### GET /purchase-orders
Lista de órdenes con filtros.

**Query params:**
- `client_id`, `status`, `date_from`, `date_to`, `search`, `page`, `per_page`

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "order_consecutive": "OC-2026-001",
      "status": "pending",
      "client": { "id": 1, "name": "..." },
      "total": 1500000,
      "created_at": "2026-01-01T00:00:00Z"
    }
  ],
  "meta": { "current_page": 1, "total": 50 }
}
```

---

### POST /purchase-orders
Crea nueva orden.

**Body (JSON):**
```json
{
  "client_id": 1,
  "observations": "Texto",
  "products": [
    {
      "product_id": 1,
      "branch_office_id": 1,
      "quantity": 10,
      "price": 50000,
      "delivery_date": "2026-02-01"
    }
  ]
}
```

---

### GET /purchase-orders/{id}
Detalle completo de una orden.

---

### PUT /purchase-orders/{id}
Actualiza datos generales de la orden.

---

### POST /purchase-orders/{id}/update-status
**Usado por Alexa** para confirmar despacho y cambiar estado.

**Body (FormData):**
```
status           = parcial_status | completed | processing | pending | cancelled
invoiceNumber    = "FAC-001"
dispatchDate     = "2026-01-15"
trackingNumber   = "GU-123456"
trm              = 4200.50
transporter      = "Coordinadora"
emails           = JSON stringified array ["email@x.com"]
parcials         = JSON stringified array de productos con parciales reales
observations     = "<p>HTML desde CKEditor</p>"
invoice_pdf      = (archivo binario, opcional)
```

**parcials structure:**
```json
[
  {
    "product_id": 1,
    "realPartials": [
      {
        "quantity": 5,
        "dispatch_date": "2026-01-15",
        "trm": 4200,
        "tracking_number": "GU-123",
        "transporter": "Aldia",
        "invoice_number": "FAC-001"
      }
    ]
  }
]
```

**Response:** `200 { "message": "Estado actualizado" }`

---

### POST /purchase-orders/{id}/observations
**Usado por Marlon** para agregar observaciones y parciales sin cambiar estado.

**Permiso requerido:** `order_observation`

**Body (JSON):**
```json
{
  "emails-tags": ["email@x.com"],
  "new_observation": "<p>HTML de observación al cliente</p>",
  "internal_observation": "<p>HTML de nota interna — solo planta</p>",
  "purchase_order_id": 123,
  "partials": [
    {
      "product_id": 1,
      "realPartials": [
        { "quantity": 3, "dispatch_date": "2026-01-16" }
      ]
    }
  ]
}
```

**Notas:**
- `new_observation` → se envía al cliente (hilo email con cliente)
- `internal_observation` → solo para equipo interno (hilo despacho), NO al cliente
- Genera email con `Re:` usando `message_id` (cliente) y `message_despacho_id` (interno) almacenados en la orden

**Response:** `200 { "message": "Observación guardada" }`

---

### POST /purchase-orders/{id}/resend
Reenvía emails de pedido y/o despacho.

---

### POST /purchase-orders/{id}/resend-proforma
Reenvía proforma.

---

### GET /purchase-orders/forecast-check
Alerta de pronóstico para una línea del formulario de OC. Compara contra el
**mes de entrega** de la línea, no contra el mes en curso.

| Parámetro | Requerido | Descripción |
|-----------|-----------|-------------|
| `client_id` | sí | Cliente de la orden |
| `product_id` | sí | Producto de la línea |
| `delivery_date` | no | Fecha de entrega de la línea. Sin ella cae al mes en curso |
| `order_id` | no | OC en edición: se excluye de lo comprometido para no contarla dos veces |

```json
{
  "success": true,
  "has_forecast": true,
  "pronostico": 100,
  "comprometido": 0,
  "disponible": 100,
  "mes": "OCTUBRE",
  "año": "2026"
}
```

`has_forecast: false` cuando no hay pronóstico manual cargado para
(nit, código, mes) — ahí no se muestra alerta.

`comprometido` = kilos ya despachados en ese mes (`partials.type = 'real'`)
**más** los pedidos en líneas de otras OCs que entregan ese mismo mes y todavía
no despachan. La lógica vive en `app/Services/ForecastAlertService.php` y es la
misma que arma el aviso del email al cliente.

---

### GET /shipment-trackings
Seguimiento de despachos. Una fila por (pedido, guía) — la guía identifica el
envío completo, con todos los parciales que van en él.

| Parámetro | Descripción |
|-----------|-------------|
| `status` | `pendiente`, `en_transito`, `entregado`, `devuelto`, `novedad`, `sin_datos` |
| `carrier` | `dhl`, `coordinadora` (las demás transportadoras aún no tienen driver) |
| `client_id` | Filtra por cliente de la OC |
| `purchase_order_id` | Guías de un pedido |
| `per_page` | Por defecto 50, máximo 200 |

Se actualiza sola con `shipments:sync-trackings`, agendado a las 6:00 am.
`entregado` y `devuelto` son finales: no se vuelven a consultar.

### GET /shipment-trackings/{id}/events
Historial completo de eventos de una guía, del más viejo al más reciente.

### POST /shipment-trackings/{id}/refresh
Consulta la transportadora en el momento, con la misma lógica del job.

---

## Coordinadora — Push Tracking / Novedades / Soluciones

Coordinadora no se consulta (es push-only): ella empuja las notificaciones a
estos endpoints. **El servicio del proveedor no tiene autenticación propia**
— la única barrera es el middleware `coordinadora.webhook` (token en la URL
+ IP whitelist opcional). Por eso van fuera del grupo `auth:sanctum`, con
`throttle:60,1`. El token nunca se loguea.

| Método | URL |
|--------|-----|
| `POST` | `/api/webhooks/coordinadora/{token}/tracking` |
| `POST` | `/api/webhooks/coordinadora/{token}/novedades` |
| `POST` | `/api/webhooks/coordinadora/{token}/soluciones` |
| `POST` | `/api/webhooks/coordinadora/{token}/test/tracking` |
| `POST` | `/api/webhooks/coordinadora/{token}/test/novedades` |
| `POST` | `/api/webhooks/coordinadora/{token}/test/soluciones` |

Las rutas `/test/*` son el mismo endpoint en ambiente de prueba (Coordinadora
no tiene servidor de staging aparte): solo quedan registradas en la bitácora,
nunca tocan `shipment_trackings` ni `shipment_tracking_events`.

### Formato de los payloads

**`tracking`** llega envuelto en Google Pub/Sub — el JSON real va en Base64
dentro de `message.data`:

```json
{
  "message": {
    "data": "eyJ0cmFja2luZ19udW1iZXIiOiIzMDM4MDAwMDU1MCIsImNvbW1lbnQiOiJFTlRSRUdBREEiLCJjb2RpZ28iOiI2IiwiZmVjaGEiOiIyMDI2LTA4LTEwIiwiaG9yYSI6IjEzOjUxOjQzIn0="
  }
}
```

Decodificado: `tracking_number`, `comment` / `desc_estado`, `codigo`, `fecha`
(`Y-m-d`), `hora` (`H:i:s`, puede traer microsegundos).

**`novedades`** y **`soluciones`** llegan como JSON plano (formato "NyS"),
identificando la guía con `numero_guia` en lugar de `tracking_number` y la
marca de tiempo en un único campo `fecha_hora` (ISO 8601 con `Z`, no
`fecha`+`hora` separados como en tracking):

```json
{
  "numero_guia": "30380000551",
  "evento": "reporte",
  "id_registro_novedad": "550e8400-e29b-41d4-a716-446655440000",
  "id_novedad": "12",
  "descripcion_novedad": "Dirección errada",
  "fecha_hora": "2026-08-10T14:00:00Z"
}
```

`soluciones` usa el mismo formato con `evento` ∈ {`aprobacion`, `rechazo`} y
sus propios campos (`id_solucion`, `descripcion_solucion`,
`observacion_rechazo`).

### Procesamiento (rutas de producción)

1. El cuerpo crudo de la petición (no `$request->all()`, que queda vacío con
   JSON mal formado) siempre queda en `courier_webhook_logs` antes de
   procesar (endpoint, ambiente, IP), pase lo que pase después: ya
   interpretado si es JSON válido y cabe en el tope de tamaño, o en Base64
   si no, para poder reprocesarlo igual.
2. Si no se puede extraer la guía (envoltura corrupta, campo ausente) →
   **400**, rechazo registrado en la bitácora.
3. La guía debe tener el formato de Coordinadora (11 dígitos numéricos) —
   esto descarta antes de tocar la base de datos el texto literal `'null'`
   que arrastran 207 parciales sucios en producción — y existir en un
   parcial vivo (`partials.deleted_at IS NULL`, `type = 'real'`) con
   `LOWER(TRIM(transporter)) = 'coordinadora'`. Si no existe → **200** (no
   400: puede ser guía de otro cliente de Coordinadora; un 400 haría que el
   proveedor reintentara indefinidamente). Solo después de este cruce se
   valida que `fecha`/`hora` (o `fecha_hora` en NyS) armen un timestamp
   válido — validarlo antes convertiría una guía ajena con timestamp raro en
   un 400 con reintentos infinitos.
4. Si existe, se ubica o crea la fila de `shipment_trackings` de esa pareja
   (orden, guía) y se agrega el evento normalizado al historial (sin
   duplicar: índice único `shipment_tracking_id + occurred_at + code` — en
   NyS el código siempre es no nulo: `id_registro_novedad` / `id_novedad` /
   `id_solucion`, o un valor fijo si el payload no trae ninguno). Una misma
   guía puede pertenecer a varias órdenes de compra (218 casos reales): el
   evento se aplica a **todas**.
5. `status`, `last_event_*` e `is_final` solo se actualizan si el evento
   entrante es igual o más reciente que el último registrado (comparando
   `fecha`/`hora`): Pub/Sub no garantiza orden ni deduplica reintentos, así
   que un evento viejo que llega tarde no puede degradar un estado más
   nuevo. El evento se guarda en el historial de todos modos. `is_final` se
   marca cuando el estado resultante es `entregado` o `devuelto`.
6. En `soluciones`, `evento: "aprobacion"` nunca reabre un despacho ya
   cerrado: si el estado actual es final se conserva; si no, pasa a
   `en_transito`. `evento: "rechazo"` (o cualquier valor no reconocido) se
   trata como `novedad`.
7. **500** solo ante un fallo real del servicio — el payload ya quedó en la
   bitácora, así que se puede reprocesar.

---

## Estados de la orden

| Valor | Descripción |
|-------|-------------|
| `pending` | Creada, sin procesar |
| `processing` | En proceso |
| `parcial_status` | Entrega parcial realizada |
| `completed` | Entregada completamente |
| `cancelled` | Cancelada |

---

## Emails automáticos

| Acción | Templates | Destinatarios |
|--------|-----------|---------------|
| Crear orden | `purchase_order` + `purchase_order_despacho` | Cliente / Equipo interno |
| updateStatus (completed/parcial) | `purchase_order_status_update` | Cliente + Equipo |
| updateStatus (pending/processing) | `purchase_order_status_changed` | Equipo |
| observations | `purchase_order_observation` x2 | Cliente (sep.) + Equipo (sep.) |

Threading por `message_id` (hilo cliente) y `message_despacho_id` (hilo interno).

---

## Componentes frontend

| Componente | Ruta |
|-----------|------|
| Lista de órdenes | `src/views/purchase-orders/PurchaseOrderList.vue` |
| Modal Alexa (estado) | `src/components/purchase-orders/StatusConfirmationModal.vue` |
| Modal Marlon (obs) | `src/components/purchase-orders/ObservationsModal.vue` |
| Formulario crear/editar | `src/components/purchase-orders/PurchaseOrderForm.vue` |
| Input TRM | `src/components/purchase-orders/TRMCalendarInput.vue` |
