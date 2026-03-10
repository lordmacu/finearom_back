# API — Proyectos

Base URL: `/api` — todos los endpoints requieren `Authorization: Bearer {token}` (auth:sanctum).

---

## Permisos requeridos

| Permiso                  | Descripción                                      |
|--------------------------|--------------------------------------------------|
| `project list`           | Listar y ver proyectos, catálogos, cotización    |
| `project create`         | Crear proyectos                                  |
| `project edit`           | Actualizar proyectos y sus subentidades          |
| `project delete`         | Eliminar proyectos                               |
| `project external status`| Marcar proyecto como Ganado/Perdido              |
| `project deliver`        | Registrar entrega de un departamento             |
| `project factor edit`    | Editar factor                                    |
| `project catalog manage` | CRUD completo de catálogos y tiempos             |

---

## Modelo de datos — Project

Un proyecto pertenece **a un cliente de la plataforma** (`client_id`) **o a un prospecto** (`prospect_id`), nunca a los dos.

| Campo             | Tipo     | Descripción                                              |
|-------------------|----------|----------------------------------------------------------|
| `client_id`       | FK       | Cliente activo de la plataforma (tabla `clients`)        |
| `prospect_id`     | FK       | Prospecto del legacy (tabla `prospects`)                 |
| `legacy_id`       | int      | ID original en proyectosold (para trazabilidad)          |
| `ejecutivo`       | string   | Nombre del ejecutivo (string retrocompatible)            |
| `ejecutivo_id`    | FK       | FK a `users` (ejecutivo de la plataforma)                |
| `nombre_prospecto`| string   | Nombre libre de prospecto cuando se crea manualmente     |
| `email_prospecto` | string   | Email libre de prospecto cuando se crea manualmente      |

---

## Prospectos

Los prospectos son clientes del legacy que no están en la tabla `clients`.
**No se mezclan con `clients`** — tienen su propia tabla `prospects`.

### GET /api/projects/ejecutivos

Lista los usuarios de la plataforma para asignar como ejecutivo.

**Respuesta 200:**
```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Ana Gómez", "email": "ana@finearom.com" }
  ]
}
```

---

## Proyectos

### GET /api/projects

Lista paginada de proyectos.

**Query params (todos opcionales):**
| Parámetro        | Tipo   | Descripción                                                  |
|------------------|--------|--------------------------------------------------------------|
| `tipo`           | string | `Colección` \| `Desarrollo` \| `Fine Fragances`              |
| `estado_externo` | string | `Ganado` \| `Perdido` \| `En espera`                        |
| `estado_interno` | string | `Pendiente` \| `En proceso` \| `Entregado`                  |
| `ejecutivo`      | string | Filtra por nombre del ejecutivo                              |
| `search`         | string | Busca en nombre del proyecto y nombre del cliente/prospecto  |
| `departamento`   | string | Muestra proyectos pendientes por departamento                |
| `page`           | int    | Página (default 1)                                          |

**Respuesta 200:**
```json
{
  "data": [
    {
      "id": 1,
      "nombre": "Proyecto A",
      "tipo": "Desarrollo",
      "estado_externo": "En espera",
      "estado_interno": "Pendiente",
      "ejecutivo": "Ana Gómez",
      "ejecutivo_id": 3,
      "client_id": 100,
      "prospect_id": null,
      "legacy_id": 4521,
      "client": { "id": 100, "client_name": "Cliente X", "nit": "900123456" },
      "prospect": null,
      "product": { "id": 7, "nombre": "COLONIA" }
    }
  ],
  "meta": {
    "total": 8332,
    "per_page": 20,
    "current_page": 1
  }
}
```

---

### POST /api/projects

Crea un proyecto. También inicializa los registros 1:1 (`project_samples`, `project_applications`, `project_evaluations`, `project_marketing`). Calcula `fecha_calculada` con `ProjectTimeService`.

**Body (JSON):**
```json
{
  "nombre": "Proyecto A",
  "client_id": 100,
  "prospect_id": null,
  "nombre_prospecto": null,
  "email_prospecto": null,
  "ejecutivo_id": 3,
  "product_id": 7,
  "tipo": "Desarrollo",
  "rango_min": 10,
  "rango_max": 50,
  "volumen": 5000,
  "trm": 4200,
  "factor": 1.15,
  "base_cliente": false,
  "proactivo": true,
  "homologacion": false,
  "internacional": false,
  "fecha_requerida": "2026-06-01",
  "fecha_creacion": "2026-03-09",
  "tipo_producto": "Shampoo"
}
```

> Regla: enviar `client_id` **o** `prospect_id` **o** `nombre_prospecto` — no mezclar.
> Si se envía `ejecutivo_id`, el campo `ejecutivo` se auto-asigna con el nombre del usuario.
> Si no se envía ninguno, `ejecutivo` se asigna al usuario autenticado.

**Campos requeridos:** `nombre`, `tipo`

**Respuesta 201:**
```json
{
  "data": { /* proyecto con relaciones cargadas */ },
  "message": "Proyecto creado"
}
```

---

### GET /api/projects/{project}

Detalle completo de un proyecto con todas sus relaciones.

**Respuesta 200:**
```json
{
  "data": {
    "id": 1,
    "nombre": "Proyecto A",
    "client_id": 100,
    "prospect_id": null,
    "client": { /* datos del cliente */ },
    "prospect": null,
    "product": { /* project_product_type */ },
    "sample": { /* project_sample */ },
    "application": { /* project_application */ },
    "evaluation": { /* project_evaluation */ },
    "marketingYCalidad": { /* project_marketing */ },
    "variants": [ { "proposals": [ /* ... */ ] } ],
    "requests": [ { "fragrance": { /* ... */ } } ],
    "fragrances": [ { "fineFragrance": { /* ... */ } } ]
  }
}
```

---

### PUT /api/projects/{project}

Actualiza un proyecto. Recalcula `fecha_calculada` si cambian `rango_min`, `rango_max`, `volumen`, `tipo`, `homologacion` o `product_id`.

**Body (JSON):** Mismos campos que store, todos opcionales. Además:
```json
{
  "obs_lab": "Observación laboratorio",
  "obs_des": "Observación desarrollo",
  "obs_mer": "Observación mercadeo",
  "obs_cal": "Observación calidad",
  "obs_esp": "Observación especiales",
  "obs_ext": "Observación externo",
  "razon_perdida": "Precio fuera de mercado"
}
```

**Respuesta 200:**
```json
{
  "data": { /* proyecto actualizado */ },
  "message": "Proyecto actualizado"
}
```

---

### DELETE /api/projects/{project}

Elimina un proyecto (soft delete).

**Respuesta 200:**
```json
{ "message": "Proyecto eliminado" }
```

---

## Subentidades del proyecto (1:1)

Todos usan `updateOrCreate`. Permiso: `project edit`.

### PUT /api/projects/{project}/sample

```json
{ "cantidad": 5, "observaciones": "Muestra enviada por correo" }
```

---

### PUT /api/projects/{project}/application

```json
{ "dosis": "2% en shampoo", "observaciones": "Aplicar en frío" }
```

---

### PUT /api/projects/{project}/evaluation

```json
{
  "tipos": ["En Cabina", "Estabilidad"],
  "observacion": "Evaluación positiva"
}
```

---

### PUT /api/projects/{project}/marketing

```json
{
  "marketing": ["Descripción Olfativa", "Pirámide Olfativa"],
  "calidad": ["MSDS Mezclas", "IFRA"],
  "obs_marketing": "Campaña Q3",
  "obs_calidad": "Requiere certificación"
}
```

---

## Variantes (solo tipo Desarrollo)

Permiso: `project edit`.

### POST /api/projects/{project}/variants
```json
{ "nombre": "Variante A", "observaciones": "Notas" }
```

### PUT /api/projects/{project}/variants/{variant}
```json
{ "nombre": "Variante A modificada", "observaciones": "Notas" }
```

### DELETE /api/projects/{project}/variants/{variant}

---

## Solicitudes (solo tipo Colección)

### POST /api/projects/{project}/requests
```json
{ "fragrance_id": 5, "cantidad": 3, "observaciones": "..." }
```

### PUT /api/projects/{project}/requests/{request}
```json
{ "fragrance_id": 5, "cantidad": 3, "observaciones": "..." }
```

### DELETE /api/projects/{project}/requests/{request}

---

## Fragancias del proyecto (solo tipo Fine Fragances)

### POST /api/projects/{project}/fragrances
```json
{ "fine_fragrance_id": 12, "cantidad": 2, "observaciones": "..." }
```

### PUT /api/projects/{project}/fragrances/{projectFragrance}
```json
{ "fine_fragrance_id": 12, "cantidad": 2, "observaciones": "..." }
```

### DELETE /api/projects/{project}/fragrances/{projectFragrance}

---

## Workflow

### PATCH /api/projects/{project}/estado-externo

Permiso: `project external status`

```json
{ "status": "Ganado" }
```
Valores: `Ganado` | `Perdido`

---

### PATCH /api/projects/{project}/entregar

Permiso: `project deliver`

```json
{ "department": "laboratorio" }
```
Valores: `desarrollo` | `laboratorio` | `mercadeo` | `calidad` | `especiales`

---

### GET /api/projects/{project}/cotizacion

Permiso: `project list`

Retorna ítems para generar cotización según tipo del proyecto.

---

## Catálogos

### GET /api/project-catalogs/product-types
Lista los tipos de producto (`project_product_types`).

### POST /api/project-catalogs/product-types
```json
{ "nombre": "NUEVO TIPO", "categoria": 1 }
```

### PUT /api/project-catalogs/product-types/{projectProductType}
### DELETE /api/project-catalogs/product-types/{projectProductType}

---

### GET /api/project-catalogs/fragrances
### POST /api/project-catalogs/fragrances
```json
{ "nombre": "required", "referencia": "", "codigo": "", "precio": 0, "precio_usd": 0, "usos": [] }
```
### PUT /api/project-catalogs/fragrances/{fragrance}
### DELETE /api/project-catalogs/fragrances/{fragrance}

---

### GET /api/project-catalogs/fine-fragrances
### POST /api/project-catalogs/fine-fragrances
```json
{ "nombre": "required", "codigo": "", "precio": 0, "precio_usd": 0, "casa_id": 1, "family_id": 1 }
```
### PUT /api/project-catalogs/fine-fragrances/{fineFragrance}
### DELETE /api/project-catalogs/fine-fragrances/{fineFragrance}

---

### GET /api/project-catalogs/houses
### GET /api/project-catalogs/families
### GET /api/project-catalogs/swissarom-references

---

## Tiempos de cálculo (project-times)

Tablas de configuración usadas por `ProjectTimeService` para calcular `fecha_calculada`.
Permiso: `project catalog manage` (lectura, escritura y borrado).

### Muestras
| Método | Endpoint |
|--------|----------|
| GET    | `/api/project-times/samples` |
| POST   | `/api/project-times/samples` |
| PUT    | `/api/project-times/samples/{timeSample}` |
| DELETE | `/api/project-times/samples/{timeSample}` |

**Body POST:** `{ "rango_min": 0, "rango_max": 11, "tipo_cliente": "pareto", "valor": 1 }`
`tipo_cliente`: `pareto` | `balance` | `none`

---

### Aplicaciones
| Método | Endpoint |
|--------|----------|
| GET    | `/api/project-times/applications` |
| POST   | `/api/project-times/applications` |
| PUT    | `/api/project-times/applications/{timeApplication}` |
| DELETE | `/api/project-times/applications/{timeApplication}` |

**Body POST:** `{ "rango_min": 0, "rango_max": 11, "tipo_cliente": "pareto", "product_id": 7, "valor": 2 }`

---

### Evaluaciones
| Método | Endpoint |
|--------|----------|
| GET    | `/api/project-times/evaluations` |
| POST   | `/api/project-times/evaluations` |
| PUT    | `/api/project-times/evaluations/{timeEvaluation}` |
| DELETE | `/api/project-times/evaluations/{timeEvaluation}` |

**Body POST:** `{ "solicitud": "En Cabina", "grupo": 1, "valor": 3 }`
`solicitud`: `En Cabina` | `Estabilidad` | `En uso` | `Triangular`

---

### Marketing (tiempos)
| Método | Endpoint |
|--------|----------|
| GET    | `/api/project-times/marketing` |
| POST   | `/api/project-times/marketing` |
| PUT    | `/api/project-times/marketing/{timeMarketing}` |
| DELETE | `/api/project-times/marketing/{timeMarketing}` |

**Body POST:** `{ "solicitud": "Descripción Olfativa", "grupo": 1, "valor": 0.5 }`
`solicitud`: `Descripción Olfativa` | `Pirámide Olfativa` | `Caja` | `Presentación` | `Presentación Cero` | `Dummie Digital` | `Dummie Fisico` | `Investigación De Mercado`

---

### Calidad (tiempos)
| Método | Endpoint |
|--------|----------|
| GET    | `/api/project-times/quality` |
| POST   | `/api/project-times/quality` |
| PUT    | `/api/project-times/quality/{timeQuality}` |
| DELETE | `/api/project-times/quality/{timeQuality}` |

**Body POST:** `{ "solicitud": "MSDS Mezclas", "grupo": 1, "valor": 2 }`
`solicitud`: `MSDS Mezclas` | `Alergénos` | `IFRA` | `Ficha Técnica` | `Certificado De Solvente`

---

### Respuestas (tiempos)
| Método | Endpoint |
|--------|----------|
| GET    | `/api/project-times/responses` |
| POST   | `/api/project-times/responses` |
| PUT    | `/api/project-times/responses/{timeResponse}` |
| DELETE | `/api/project-times/responses/{timeResponse}` |

**Body POST:** `{ "grupo": 1, "num_variantes_min": 1, "num_variantes_max": 2, "valor": 3 }`

---

### Homologaciones (tiempos)
| Método | Endpoint |
|--------|----------|
| GET    | `/api/project-times/homologations` |
| POST   | `/api/project-times/homologations` |
| PUT    | `/api/project-times/homologations/{timeHomologation}` |
| DELETE | `/api/project-times/homologations/{timeHomologation}` |

**Body POST:** `{ "grupo": 1, "num_variantes_min": 1, "num_variantes_max": 2, "valor": 10 }`

---

### Fine Fragrances (tiempos)
| Método | Endpoint |
|--------|----------|
| GET    | `/api/project-times/fine` |
| POST   | `/api/project-times/fine` |
| PUT    | `/api/project-times/fine/{timeFine}` |
| DELETE | `/api/project-times/fine/{timeFine}` |

**Body POST:** `{ "tipo_cliente": "pareto", "num_fragrances_min": 1, "num_fragrances_max": 5, "valor": 3 }`

---

### Clasificación de grupos
| Método | Endpoint |
|--------|----------|
| GET    | `/api/project-times/group-classifications` |
| POST   | `/api/project-times/group-classifications` |
| PUT    | `/api/project-times/group-classifications/{groupClassification}` |
| DELETE | `/api/project-times/group-classifications/{groupClassification}` |

**Body POST:** `{ "rango_min": 0, "rango_max": 11, "tipo_cliente": "pareto", "valor": 4 }`
`valor` es el número de grupo (1–6).

---

## Migración de datos

### Script de migración

```bash
./migrate-legacy.sh
```

Ejecuta en orden:
1. `php artisan migrate` — crea tablas nuevas (prospects, etc.)
2. `ProjectTimesSeeder` — siembra `project_product_types` + 9 tablas de tiempos
3. `ProspectImportSeeder` — importa prospectos y proyectos del legacy

**El script es idempotente** — si ya fue ejecutado, salta los registros existentes sin duplicar.

### Tablas de migración

| Tabla nueva       | Origen legacy         | Filas |
|-------------------|-----------------------|-------|
| `projects`        | `proyectos`           | 8,332 |
| `project_samples` | `muestra`             | ~8,338|
| `project_evaluations`| `evaluacion`       | ~8,338|
| `project_applications`| `aplicacion`      | ~8,310|
| `project_marketing`| `marketing_y_calidad`| ~8,338|
| `prospects`       | `clientes` (sin NIT o no en plataforma) | 782 |

### Retrocompatibilidad

- Proyectos con `client_id` → cliente activo de la plataforma (1,179 proyectos)
- Proyectos con `prospect_id` → cliente legacy importado como prospecto (7,153 proyectos)
- `legacy_id` → ID original en `proyectosold` para trazabilidad
- NITs faltantes en legacy → asignados como `PROSPECT-{legacy_id}`
