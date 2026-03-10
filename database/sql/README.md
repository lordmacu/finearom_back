# Scripts de Migración Legacy → Finearom

Estos scripts migran la data del sistema legacy (`syssatau_arom`) al nuevo backend de Finearom.
**No tocan ni borran ninguna tabla existente en producción.**

## Orden de ejecución

```bash
# 1. Importar dump legacy en una base de datos separada llamada "legacy"
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS legacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p --default-character-set=utf8mb4 legacy < /ruta/al/finearom_dump.sql

# 2. Correr los scripts en orden (conectado a finearom, con acceso cross-DB a legacy.*)
mysql -u root -p finearom < 01_catalogos.sql
mysql -u root -p finearom < 02_tiempos_aplicacion.sql
mysql -u root -p finearom < 03_proyectos.sql
```

## Qué hace cada script

| Script | Tablas destino | Fuente legacy |
|--------|---------------|---------------|
| `01_catalogos.sql` | fragrance_houses, fragrance_families, fine_fragrances, fragrances, swissarom_references | casas, general_fine_fragances, fine_fragances, fragancias_coleccion, referencias_swissarom |
| `02_tiempos_aplicacion.sql` | time_applications | tiempos_aplicacion |
| `03_proyectos.sql` | projects, project_samples, project_applications, project_evaluations, project_marketing, project_variants, project_requests, project_fragrances | proyectos y sub-tablas |

## Incompatibilidades manejadas

- **client_id**: Mapeado por NIT (legacy `clientes.nit` → `clients.nit`). Proyectos sin cliente con NIT coincidente son **omitidos**.
- **product_id**: Mapeado directamente por ID (IDs iguales — `project_product_types` usa mismos IDs que legacy `productos`).
- **tipo_cliente**: `A`→`pareto`, `B`→`balance`, `C/D`→`none`
- **Booleanos**: `'Si'`→`1`, `'No'`→`0`
- **JSON**: `tipos`, `marketing`, `calidad` convertidos de texto separado por comas a JSON array
- **Charset**: Legacy en latin1 → se convierte automáticamente al importar con `--default-character-set=utf8mb4`
- **IDs preservados**: fragrances, fine_fragrances, fragrance_houses, fragrance_families, project_variants, project_requests, project_fragrances

## Seguridad

Todos los INSERT usan `INSERT IGNORE` — no reemplaza datos existentes ni borra nada.
