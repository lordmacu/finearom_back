# Catálogos de Marketing con carpetas virtuales

Envases, diseños de etiqueta y pirámides comparten tabla (`project_catalog_items`), carpetas
(`project_catalog_folders`) y controladores. `{tipo}` = `envase` | `etiqueta` | `piramide`.

- Las carpetas son **virtuales** (solo en base de datos), con `parent_id`, sin límite de niveles.
  Un ítem vive en una carpeta (`folder_id`, `null` = raíz). Las carpetas no se mezclan entre tipos.
- Las **imágenes siguen en disco** (`photo_path`, disco `local`) y se sirven por endpoint.
- Un proyecto elige ítems de los tres tipos en `project_catalog_item_project`.
  `Project::envelopeTypes()` es el filtro `tipo = envase`.

## Desde el proyecto (permiso `project list`)
| Método | URL | Descripción |
|---|---|---|
| GET | `/api/project-catalog-items/{tipo}/browse?folder_id=&search=&page=&per_page=` | Subcarpetas e imágenes **activas** de una carpeta (raíz si falta `folder_id`). Con `search` busca en todo el catálogo y no devuelve carpetas. Respuesta: `{folder, breadcrumb[], folders[], data[], meta}` |
| GET | `/api/project-catalog-items/{tipo}` | Listado plano paginado de ítems activos |
| GET | `/api/project-catalog-items/item/{item}/photo` | Foto del ítem |
| PUT | `/api/projects/{project}/catalog-items/{tipo}` (`project edit`) | Reemplaza la selección de ese tipo con `item_ids[]`, sin tocar los otros tipos |

Los envases también se pueden fijar con `envelope_type_ids[]` en el create/update del proyecto.

## Administración (permiso `envelope type manage`)
| Método | URL | Descripción |
|---|---|---|
| GET | `/api/admin/project-catalog-items/{tipo}/browse` | Igual que arriba pero incluye inactivos |
| POST | `/api/admin/project-catalog-items` | Crea ítem (`tipo`, `name`, `category`, `photo`, `active`, `folder_id`) |
| PUT | `/api/admin/project-catalog-items/{item}` | Edita o mueve (`folder_id`; `null` = raíz) |
| DELETE | `/api/admin/project-catalog-items/{item}` | Elimina ítem y foto |
| GET | `/api/admin/project-catalog-folders/{tipo}` | Lista plana de carpetas con su `path` ("A / B") |
| POST | `/api/admin/project-catalog-folders` | Crea carpeta (`tipo`, `name`, `parent_id`) |
| PUT | `/api/admin/project-catalog-folders/{folder}` | Renombra o mueve (`parent_id`); impide ciclos |
| DELETE | `/api/admin/project-catalog-folders/{folder}` | Solo si está vacía (422 si tiene subcarpetas o ítems) |

Se eliminaron `/api/envelope-types*` y `/api/admin/envelope-types*`: la migración
`2026_10_08_100000_...` pasa los envases y su selección a la tabla unificada (las tablas
`envelope_types` y `project_envelope_type` quedan sin uso, no se borran).
