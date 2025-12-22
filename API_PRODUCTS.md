# API de Productos

Documentación del API REST para el módulo **Productos** (replica mejorada de `/admin/product` del legacy).

- **Base URL**: `/api/products`
- **Auth**: token Sanctum en el header `Authorization: Bearer {token}`
- **Permisos**:
  - `product list`: listar
  - `product create`: crear / importar
  - `product edit`: actualizar
  - `product delete`: eliminar

## Endpoints

### `GET /api/products`
Listar productos con filtros.

**Query params**
- `search` (string) : filtra por nombre o código.
- `client_id` (int) : filtra por cliente.
- `code` (string)   : código exacto.
- `page` (int) & `per_page` (int, default 15) : paginación.
- `paginate` (`true|false`) : desactiva paginación y retorna los primeros `per_page`.
- `sort_by` (string) & `sort_direction` (`asc|desc`) : ordenamiento, default `id desc`.

**Response**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "ABC-01",
      "product_name": "Aroma Verde",
      "price": 1500,
      "client_id": 3,
      "client": { "id": 3, "client_name": "Cliente X", "nit": "900..." }
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1,
    "paginate": true
  }
}
```

### `POST /api/products`
Crear producto.

**Body**
```json
{
  "code": "ABC-01",
  "product_name": "Aroma Verde",
  "price": 1500,
  "client_id": 3
}
```

### `PUT /api/products/{id}`
Actualizar producto (código no se cambia en este endpoint).

**Body**
```json
{
  "product_name": "Aroma Verde MAX",
  "price": 1800,
  "client_id": 3
}
```

### `DELETE /api/products/{id}`
Elimina un producto.

### `POST /api/products/import`
Importar/actualizar productos desde Excel/CSV/TXT.

**Formato esperado**
- Primera fila: encabezados case-insensitive: `code`, `product_name`, `price`, `nit` **o** `client_id`.
- Se identifica el producto por combinación `code + client_id`.
- Si existe, se actualiza; si no, se crea.

**Body**
`multipart/form-data` con:
- `file`: `.xls`, `.xlsx`, `.csv` o `.txt`.

**Respuesta**
```json
{
  "success": true,
  "message": "Importacion procesada",
  "data": {
    "updated": 10,
    "created": 5,
    "errors": [
      { "row": 4, "message": "Cliente no encontrado" }
    ]
  }
}
```

### `GET /api/products/export`
Exporta los productos filtrados a Excel (`.xlsx`, mismas query params que el listado).

Columnas: `Code`, `Product Name`, `Price`, `Client NIT`, `Client Name`, `Operation` (vacía para compatibilidad con legacy).

## Notas de uso
- En los listados ya retorna el `client` asociado (`id`, `client_name`, `nit`) para pintar el nombre en frontend.
- `per_page` está acotado (1-500). Para combos rápidos usar `paginate=false&per_page=50`.
- Asegúrate de tener creados los permisos en base de datos (incluidos en `DatabaseSeeder`).
