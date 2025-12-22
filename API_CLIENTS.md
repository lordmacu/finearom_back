# API de Clientes y Sucursales

Endpoints REST para el módulo que reemplaza `/admin/client` del legacy.

- **Base URL**: `/api`
- **Auth**: Sanctum (`Authorization: Bearer {token}`)
- **Permisos**: `client list|create|edit|delete`, `branch office list|create|edit|delete`, `executive list|create|edit|delete`.

## Clientes
- `GET /clients` — filtros: `search`, `executive_email`, `sort_by` (`id,client_name,nit,email,executive_email`), `sort_direction`, `per_page`, `paginate=false`.
- `POST /clients` — body: `client_name`, `nit?`, `email`, `executive_email?`, `dispatch_confirmation_email?`, `accounting_contact_email?`, `portfolio_contact_email?`, `compras_email?`, `logistics_email?`.
- `PUT /clients/{id}` — mismas reglas.
- `DELETE /clients/{id}`
- `POST /clients/import` — `multipart/form-data` `file` (`xls|xlsx|csv|txt`), headers esperados: `client_name`, `nit`, `email` (+ opcionales `executive_email`, `dispatch_confirmation_email`, `accounting_contact_email`, `portfolio_contact_email`, `compras_email`, `logistics_email`). Upsert por `nit`.
- `GET /clients/export` — XLSX con columnas `Client Name, NIT, Email, Executive Email, Dispatch Email, Accounting Email, Portfolio Email, Compras Email, Logistics Email, Operation`.
- `GET /clients/{id}/autofill-link` — retorna link firmado para autollenado.
- `POST /clients/{id}/autofill/send` — envía correo de autollenado al cliente (usa todos los correos válidos: email principal, ejecutivo, despacho, contabilidad, cartera, compras, logística).
- `POST /clients/autofill` — body `{ client_ids: [1,2] }` envía correos de autollenado a los clientes listados.
- `POST /clients/autocreation` — crea cliente rápido + sucursal principal y envía correo de autocreación. Body: `client_name` (req), `email` (req), `nit?`, `executive_email?`.

## Sucursales
- `GET /clients/{clientId}/branch-offices`
- `POST /clients/{clientId}/branch-offices` — body: `name`, `delivery_address`, `delivery_city`, `nit?`, `billing_address?`, `general_observations?`, `main_function?`.
- `PUT /clients/{clientId}/branch-offices/{officeId}` — mismos campos.
- `DELETE /clients/{clientId}/branch-offices/{officeId}`
- `POST /branch-offices/import` — `file` (`xls|xlsx|csv|txt`) headers: `name`, `delivery_address`, `delivery_city`, `client_id` **o** `nit` (para buscar cliente), opcionales `billing_address`, `general_observations`, `main_function`.
- (Para compatibilidad con columnas actuales) soporta además `billing_city`, `phone`, `shipping_observations`; `branch`, `contact`, `contact_portfolio` se rellenan por defecto.
- `GET /branch-offices/export` — XLSX con columnas `ID, NIT, Delivery Address, Delivery City, Client Name, Operation`.

## Ejecutivos
- `GET /executives`
- `POST /executives` — `name`, `email`, `phone?`, `is_active?`
- `PUT /executives/{id}` — idem.
- `DELETE /executives/{id}`

> El link de autollenado se construye con `config('app.frontend_url') ?? config('app.url')` y el token cifrado del cliente: `/clients/autofill?token={token}`.
