# API de Cartera

Documentaci\u00f3n del API REST para el m\u00f3dulo de **Cartera** (dashboard / seguimiento).

## Informaci\u00f3n General

- **Base URL**: `/api/cartera`
- **Autenticaci\u00f3n**: Requerida (Sanctum Token)
- **Formato de respuesta**: JSON

## Permisos requeridos

Estos endpoints usan middleware `can:*`:

- Todos los endpoints de este m\u00f3dulo requieren **`cartera view`**
- Importaci\u00f3n requiere **`cartera import`** y guardado requiere **`cartera edit`**
- Estado de cartera requiere **`cartera estado view`** y env\u00edo a cola requiere **`cartera estado send`**

## Headers comunes

```http
Authorization: Bearer {token}
Accept: application/json
```

---

## Par\u00e1metros comunes (filtros)

Varios endpoints aceptan estos query params:

- `is_monthly` (opcional): `true|false` (por defecto `true`)
- `week_number` (opcional): `1|2|3|4` (solo aplica si `is_monthly=false`)
- `executive_email` (opcional): string (email)
- `client_id` (opcional): int
- `catera_type` (opcional): `nacional|internacional`

El rango de fechas **se calcula autom\u00e1ticamente** seg\u00fan el periodo seleccionado (mensual o semana del mes actual) usando zona horaria `America/Bogota`.

---

## 1) Resumen global de cartera

Calcula las m\u00e9tricas principales (proyecci\u00f3n desde parciales, deuda actual y mora actual).

**Endpoint**: `GET /api/cartera/summary`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": {
    "projected_from_partials": 123456.78,
    "current_debt": 98765.43,
    "overdue_debt": 1000.00,
    "projection_vs_debt_diff": 24691.35
  },
  "meta": {
    "from": "2025-12-01",
    "to": "2025-12-31"
  }
}
```

---

## 2) Listado de facturas / clientes (rango)

Devuelve el listado de facturas con c\u00e1lculos de deuda y mora para el periodo seleccionado (seg\u00fan `fecha_recaudo`).

**Endpoint**: `GET /api/cartera/clients`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "client_id": 10,
      "client_name": "Cliente Ejemplo",
      "nit": "900000000",
      "numero_factura": "FV-123",
      "fecha_recaudo": "2025-12-05",
      "fecha_vencimiento": "2025-12-20",
      "valor_cancelado": 5000000,
      "current_debt": 5000000,
      "overdue_amount": 0,
      "estado_pagado": "PENDIENTE",
      "catera_type": "nacional"
    }
  ],
  "meta": {
    "from": "2025-12-01",
    "to": "2025-12-31",
    "total": 1
  }
}
```

---

## 3) Ejecutivas (para filtros)

Lista de ejecutivas encontradas en `clients.executive_email` (soporta JSON o string separado por comas).

**Endpoint**: `GET /api/cartera/executives`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": [
    { "email": "ejecutiva@empresa.com", "name": "Ejecutiva" }
  ]
}
```

---

## 4) Clientes (para filtros)

Lista de clientes (con `id`, `client_name`, `nit`) que aparecen en `recaudos`.

**Endpoint**: `GET /api/cartera/customers`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": [
    { "id": 10, "client_name": "Cliente Ejemplo", "nit": "900000000" }
  ]
}
```

---

## 5) Historial de una factura (cartera)

Busca el historial de snapshots en `cartera` para una factura exacta (`documento`).

**Endpoint**: `GET /api/cartera/invoice-history?documento={DOCUMENTO}`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "nit": "900000000",
      "documento": "FV-123",
      "fecha_cartera": "2025-12-18",
      "vence": "2025-12-20",
      "saldo_contable": 1000000,
      "catera_type": "nacional"
    }
  ],
  "meta": {
    "documento": "FV-123",
    "total": 1
  }
}
```

---

## 6) Importar Cartera (Excel -> preview)

Sube uno o varios archivos Excel y devuelve los registros procesados para preview.
Replica la lógica de legacy (`/admin/cartera/importar`):

- Lee encabezados desde fila 7 (data desde fila 8)
- Filtra por rango de días (`dias_mora`..`dias_cobro`)
- Deriva `catera_type` según el nombre del archivo (`EXTERIOR` => `internacional`, si no `nacional`)

**Endpoint**: `POST /api/cartera/import`

**Content-Type**: `multipart/form-data`

**Body (form-data)**:
- `dias_mora`: int
- `dias_cobro`: int
- `files[]`: uno o más archivos `.xls` o `.xlsx`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Archivos procesados exitosamente.",
  "data": [
    {
      "nit": "900000000",
      "nombre_empresa": "Cliente Ejemplo",
      "documento": "FV-123",
      "fecha": "2025-12-01",
      "vence": "2025-12-10",
      "dias": 5,
      "saldo_contable": "1000000",
      "vencido": "1000000",
      "saldo_vencido": "1000000",
      "catera_type": "nacional"
    }
  ],
  "meta": { "total": 1 }
}
```

---

## 7) Guardar Snapshot de Cartera (preview seleccionado -> DB)

Guarda los registros seleccionados en la tabla `cartera`, borrando primero el snapshot de la misma `fecha_cartera`.
Replica la lógica de legacy (`/admin/cartera/guardar`).

**Endpoint**: `POST /api/cartera/store`

**Body**:
```json
{
  "fecha": "2025-12-18",
  "fechaFrom": "2025-12-18",
  "fechaTo": "2025-12-25",
  "cartera": [
    {
      "nit": "900000000",
      "ciudad": "BOGOTA",
      "cuenta": "130505",
      "descripcion_cuenta": "CLIENTES",
      "documento": "FV-123",
      "fecha": "2025-12-01",
      "vence": "2025-12-10",
      "dias": 5,
      "saldo_contable": "1000000",
      "vencido": "1000000",
      "saldo_vencido": "1000000",
      "nombre_empresa": "Cliente Ejemplo",
      "catera_type": "nacional"
    }
  ]
}
```

**Respuesta Exitosa** (201 Created):
```json
{
  "success": true,
  "message": "Cartera guardada exitosamente.",
  "data": {
    "fecha_cartera": "2025-12-18",
    "count": 1
  }
}
```

---

## 8) Estado de Cartera (por fecha)

Carga la cartera agrupada por NIT para una fecha (`fecha_cartera`) y devuelve:
- cuentas (documentos)
- totales vencidos/por vencer
- emails sugeridos (bloqueo y cartera)
- estado de env\u00edo previo (si existe en `email_dispatch_queues`)

**Endpoint**: `GET /api/cartera/estado?fecha=YYYY-MM-DD`

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "data": [
    {
      "client_name": "Cliente Ejemplo",
      "nit": "900000000",
      "cuentas": [
        {
          "documento": "FV-123",
          "fecha": "2025-12-01",
          "vence": "2025-12-10",
          "dias": -5,
          "catera_type": "nacional",
          "saldo_contable": 1000000,
          "saldo_vencido": 1000000
        }
      ],
      "total_vencidos": 1000000,
      "total_por_vencer": 0,
      "dispatch_confirmation_email": ["cartera@finearom.com"],
      "emails": ["cliente@empresa.com"],
      "status_sender_block": "no_sent",
      "status_sender_balance": "no_sent",
      "isChecked": true
    }
  ],
  "meta": { "fecha": "2025-12-18", "total": 1 }
}
```

---

## 9) Enviar a cola (Cartera / Bloqueo)

Agrega a `email_dispatch_queues` los NITs seleccionados para ser enviados por un proceso externo.

**Endpoint**: `POST /api/cartera/estado/queue`

**Body**:
```json
{
  "date": "2025-12-18",
  "type_queue": "balance_notification",
  "data": [
    {
      "nit": "900000000",
      "dispatch_confirmation_email": ["cartera@finearom.com"],
      "emails": ["cliente@empresa.com"]
    }
  ]
}
```

**type_queue permitido**:
- `balance_notification` (Enviar Cartera)
- `order_block` (Enviar Bloqueo)
