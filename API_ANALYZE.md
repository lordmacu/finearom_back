# API - Análisis de Clientes (Analyze)

Endpoints para replicar y mejorar la pantalla legacy `/analyze?status=...&creation_date=...` pero en modo API (backend) + frontend.

## Autenticación
- Requiere `Bearer Token` (Sanctum).

## Permisos
- Ver análisis: `analysis view`
- Editar parcial: `partial edit`
- Eliminar parcial: `partial delete`

Nota: el rol `super-admin` pasa todos los permisos (Gate::before).

---

## 1) Listar clientes (resumen)

`GET /api/analyze/clients`

### Query params
- `status` (opcional): `completed | pending | processing | parcial_status`
- `type` (opcional): `real | temporal` (default `real`)
- `from` y `to` (opcional): fechas (ej: `2025-12-01`)
- `creation_date` (opcional): formato legacy `YYYY-MM-DD - YYYY-MM-DD`
- `page` (opcional): default 1
- `per_page` (opcional): default 10 (max 5000)
- `paginate` (opcional): `true|false` (default `true`). Si es `false`, retorna todos los clientes (hasta `per_page`) sin paginación.

### Respuesta (200)
```json
{
  "success": true,
  "data": [
    {
      "client_id": 1,
      "client_name": "Cliente X",
      "nit": "900123123",
      "partials_count": 12,
      "total_usd": 1200.5,
      "total_cop": 4800000,
      "total_cop_siigo": 5100000
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 10,
    "total": 50,
    "last_page": 5,
    "from": "2025-12-01",
    "to": "2025-12-18",
    "paginate": true
  },
  "totals": {
    "total_cop": 123456789,
    "total_usd": 98765.43,
    "total_cop_siigo": 130000000
  }
}
```

Nota: `total_cop_siigo` en `data` es la suma de `siigo_sales.valor` para el NIT del cliente en el rango de meses. En `totals` es la suma global de todos los clientes.

---

## 2) Ver parciales de un cliente (detalle)

`GET /api/analyze/clients/{clientId}/partials`

### Query params
Los mismos filtros que el endpoint de clientes (`status`, `type`, `from/to` o `creation_date`).

### Respuesta (200)
```json
{
  "success": true,
  "data": [
    {
      "consecutivo": "PO-1001",
      "product": "Producto A",
      "partial": 123,
      "quantity": 10,
      "price_usd": 12.5,
      "trm": 4100,
      "trm_real": 4125,
      "defaultTrm": "si",
      "is_muestra": 0,
      "total": 512500,
      "date": "2025-12-18"
    }
  ],
  "meta": {
    "from": "2025-12-01",
    "to": "2025-12-18"
  }
}
```

---

## 3) Ver ventas Siigo de un cliente

`GET /api/analyze/clients/{nit}/siigo`

Requiere permiso `analysis view`.

El parámetro `{nit}` es el NIT del cliente (string), que se cruza con `siigo_sales.nit`.

### Query params
- `from` y `to` (opcional): fechas (ej: `2025-01-01`). Se convierten a formato `YYYY-MM` para comparar con `siigo_sales.mes`.
- `creation_date` (opcional): formato legacy `YYYY-MM-DD - YYYY-MM-DD`.

### Respuesta (200)
```json
{
  "success": true,
  "data": [
    {
      "product_code": "PROD-001",
      "product_name": "Fragancia X",
      "mes": "2025-01",
      "cantidad": 10,
      "valor": 500000,
      "precio_unitario": 50000
    }
  ],
  "total": 500000,
  "meta": {
    "from": "2025-01-01",
    "to": "2025-12-31"
  }
}
```

Nota: `product_name` puede ser `null` si el `product_code` de Siigo no tiene correspondencia en la tabla `products`.

---

## 4) Editar parcial

`PUT /api/analyze/partials/{partialId}`

### Body
```json
{
  "dispatch_date": "2025-12-18",
  "quantity": 5,
  "trm": 4100
}
```

### Respuesta (200)
```json
{
  "success": true,
  "message": "Parcial actualizado",
  "data": {
    "id": 123,
    "quantity": 5,
    "trm": 4100,
    "dispatch_date": "2025-12-18",
    "updated_at": "2025-12-18T15:30:00.000000Z"
  }
}
```

---

## 5) Eliminar parcial

`DELETE /api/analyze/partials/{partialId}`

### Respuesta (200)
```json
{
  "success": true,
  "message": "Parcial eliminado"
}
```
