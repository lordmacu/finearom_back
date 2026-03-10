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
