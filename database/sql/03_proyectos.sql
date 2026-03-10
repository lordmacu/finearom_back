-- =============================================================================
-- 03_proyectos.sql
-- Migra proyectos y sus sub-tablas del legacy al nuevo backend.
-- Requiere 01_catalogos.sql y 02_tiempos_aplicacion.sql ejecutados.
-- Requiere que los clients ya existan en la BD (vinculados por NIT).
-- Usa INSERT IGNORE — no borra ni sobreescribe data existente.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- projects ← legacy.proyectos
-- client_id: mapeado por NIT (legacy clientes.nit → clients.nit)
-- product_id: mismo ID (project_product_types preserva IDs del legacy)
-- Proyectos sin cliente con NIT coincidente son omitidos (INNER JOIN)
-- Booleanos: 'Si'→1, 'No'→0
-- =============================================================================
INSERT IGNORE INTO projects (
    id, nombre, client_id, product_id, tipo,
    rango_min, rango_max, volumen,
    base_cliente, proactivo, homologacion, internacional,
    fecha_requerida, fecha_creacion, fecha_calculada, fecha_entrega,
    tipo_producto, trm, factor, ejecutivo,
    estado_externo, fecha_externo, ejecutivo_externo,
    estado_interno, ejecutivo_interno,
    estado_desarrollo, fecha_desarrollo, ejecutivo_desarrollo,
    estado_laboratorio, fecha_laboratorio, ejecutivo_laboratorio,
    estado_mercadeo, fecha_mercadeo, ejecutivo_mercadeo,
    estado_calidad, fecha_calidad, ejecutivo_calidad,
    estado_especiales, fecha_especiales, ejecutivo_especiales,
    obs_lab, obs_des, obs_mer, obs_cal, obs_esp, obs_ext,
    actualizado,
    created_at, updated_at
)
SELECT
    p.id,
    CONVERT(p.nombre USING utf8mb4),
    nc.id                                          AS client_id,
    p.producto_id                                  AS product_id,
    CONVERT(p.tipo USING utf8mb4),
    p.rango_min,
    p.rango_max,
    p.volumen,
    IF(p.base_cliente = 'Si', 1, 0),
    IF(p.proactivo = 'Si', 1, 0),
    IF(p.homologacion = 'Si', 1, 0),
    IF(p.internacional = 'Si', 1, 0),
    NULLIF(p.fecha_requerida, '0000-00-00'),
    p.fecha_creacion,
    NULLIF(p.fecha_calculada, '0000-00-00'),
    NULLIF(p.fecha_entrega, '0000-00-00'),
    CONVERT(p.tipo_producto USING utf8mb4),
    p.trm,
    p.factor,
    CONVERT(p.ejecutivo USING utf8mb4),
    CONVERT(p.estado_externo USING utf8mb4),
    NULLIF(p.fecha_externo, '0000-00-00'),
    NULLIF(CONVERT(p.ejecutivo_externo USING utf8mb4), '0'),
    CONVERT(p.estado_interno USING utf8mb4),
    NULLIF(CONVERT(p.ejecutivo_interno USING utf8mb4), '0'),
    p.estado_desarrollo,
    NULLIF(p.fecha_desarrollo, '0000-00-00'),
    NULLIF(CONVERT(p.ejecutivo_desarrollo USING utf8mb4), '0'),
    p.estado_laboratorio,
    NULLIF(p.fecha_laboratorio, '0000-00-00'),
    NULLIF(CONVERT(p.ejecutivo_laboratorio USING utf8mb4), '0'),
    p.estado_mercadeo,
    NULLIF(p.fecha_mercadeo, '0000-00-00'),
    NULLIF(CONVERT(p.ejecutivo_mercadeo USING utf8mb4), '0'),
    p.estado_calidad,
    NULLIF(p.fecha_calidad, '0000-00-00'),
    NULLIF(CONVERT(p.ejecutivo_calidad USING utf8mb4), '0'),
    p.estado_especiales,
    NULLIF(p.fecha_especiales, '0000-00-00'),
    NULLIF(CONVERT(p.ejecutivo_especiales USING utf8mb4), '0'),
    CONVERT(p.obs_lab USING utf8mb4),
    CONVERT(p.obs_des USING utf8mb4),
    CONVERT(p.obs_mer USING utf8mb4),
    CONVERT(p.obs_cal USING utf8mb4),
    CONVERT(p.obs_esp USING utf8mb4),
    CONVERT(p.obs_ext USING utf8mb4),
    p.actualizado,
    NOW(),
    NOW()
FROM legacy.proyectos p
INNER JOIN legacy.clientes lc ON lc.id = p.cliente_id
-- NIT limpio: quita guión+dígito verificador, tabs y espacios
INNER JOIN clients nc ON nc.nit = TRIM(
    REPLACE(
        SUBSTRING_INDEX(REPLACE(lc.nit, CHAR(9), ''), '-', 1)
    , ' ', '')
)
-- Solo NIT con al menos 6 caracteres (descarta PEND, P, direcciones, etc.)
AND LENGTH(TRIM(REPLACE(SUBSTRING_INDEX(REPLACE(lc.nit, CHAR(9),''),'-',1),' ',''))) >= 6
LEFT JOIN project_product_types ppt ON ppt.id = p.producto_id;

-- =============================================================================
-- project_samples ← legacy.muestra
-- Solo para proyectos que fueron importados
-- =============================================================================
INSERT IGNORE INTO project_samples (id, project_id, cantidad, observaciones, created_at, updated_at)
SELECT
    m.id,
    m.proyecto_id,
    m.cantidad,
    CONVERT(m.observaciones USING utf8mb4),
    NOW(),
    NOW()
FROM legacy.muestra m
WHERE m.proyecto_id IN (SELECT id FROM projects);

-- =============================================================================
-- project_applications ← legacy.aplicacion
-- =============================================================================
INSERT IGNORE INTO project_applications (id, project_id, dosis, observaciones, created_at, updated_at)
SELECT
    a.id,
    a.proyecto_id,
    a.dosis,
    CONVERT(a.observaciones USING utf8mb4),
    NOW(),
    NOW()
FROM legacy.aplicacion a
WHERE a.proyecto_id IN (SELECT id FROM projects);

-- =============================================================================
-- project_evaluations ← legacy.evaluacion
-- tipos: texto separado por coma → JSON array
-- Ej: 'en cabina,triangular' → '["en cabina","triangular"]'
-- Campos vacíos → NULL
-- =============================================================================
INSERT IGNORE INTO project_evaluations (id, project_id, tipos, observacion, created_at, updated_at)
SELECT
    e.id,
    e.proyecto_id,
    CASE
        WHEN TRIM(e.tipos) = '' OR e.tipos IS NULL THEN NULL
        ELSE CONCAT('["', REPLACE(REPLACE(TRIM(e.tipos), ', ', ','), ',', '","'), '"]')
    END,
    CONVERT(e.observacion USING utf8mb4),
    NOW(),
    NOW()
FROM legacy.evaluacion e
WHERE e.proyecto_id IN (SELECT id FROM projects);

-- =============================================================================
-- project_marketing ← legacy.marketing_y_calidad
-- marketing y calidad: texto separado por coma → JSON array
-- =============================================================================
INSERT IGNORE INTO project_marketing (id, project_id, marketing, calidad, obs_marketing, obs_calidad, created_at, updated_at)
SELECT
    mk.id,
    mk.proyecto_id,
    CASE
        WHEN TRIM(mk.marketing) = '' OR mk.marketing IS NULL THEN NULL
        ELSE CONCAT('["', REPLACE(REPLACE(TRIM(mk.marketing), ', ', ','), ',', '","'), '"]')
    END,
    CASE
        WHEN TRIM(mk.calidad) = '' OR mk.calidad IS NULL THEN NULL
        ELSE CONCAT('["', REPLACE(REPLACE(TRIM(mk.calidad), ', ', ','), ',', '","'), '"]')
    END,
    CONVERT(mk.obs_marketing USING utf8mb4),
    CONVERT(mk.obs_calidad USING utf8mb4),
    NOW(),
    NOW()
FROM legacy.marketing_y_calidad mk
WHERE mk.proyecto_id IN (SELECT id FROM projects);

-- =============================================================================
-- project_variants ← legacy.variantes (solo para proyectos de Desarrollo)
-- IDs preservados (project_proposals los referencia como variant_id)
-- =============================================================================
INSERT IGNORE INTO project_variants (id, project_id, nombre, observaciones, created_at, updated_at)
SELECT
    v.id,
    v.proyecto_id,
    CONVERT(v.nombre USING utf8mb4),
    CONVERT(v.observaciones USING utf8mb4),
    NOW(),
    NOW()
FROM legacy.variantes v
WHERE v.proyecto_id IN (SELECT id FROM projects);

-- =============================================================================
-- project_requests ← legacy.solicitudes (solo para proyectos de Colección)
-- IDs preservados
-- fragrance_id referencia fragrances (ya importados en 01_catalogos.sql)
-- =============================================================================
INSERT IGNORE INTO project_requests (id, project_id, fragrance_id, tipo, porcentaje, nombre_asociado, created_at, updated_at)
SELECT
    s.id,
    s.proyecto_id,
    s.fragancia_id,
    s.tipo,
    s.porcentaje,
    CONVERT(s.nombre_asociado USING utf8mb4),
    NOW(),
    NOW()
FROM legacy.solicitudes s
WHERE s.proyecto_id IN (SELECT id FROM projects)
  AND s.fragancia_id IN (SELECT id FROM fragrances);

-- =============================================================================
-- project_fragrances ← legacy.proyecto_fragancias (solo Fine Fragances)
-- IDs preservados
-- fine_fragrance_id referencia fine_fragrances (ya importados en 01_catalogos.sql)
-- =============================================================================
INSERT IGNORE INTO project_fragrances (id, project_id, fine_fragrance_id, gramos, created_at, updated_at)
SELECT
    pf.id,
    pf.proyecto_id,
    pf.fine_fragance_id,
    pf.gramos,
    NOW(),
    NOW()
FROM legacy.proyecto_fragancias pf
WHERE pf.proyecto_id IN (SELECT id FROM projects)
  AND pf.fine_fragance_id IN (SELECT id FROM fine_fragrances);

SET FOREIGN_KEY_CHECKS = 1;

-- Resumen
SELECT 'projects'          AS tabla, COUNT(*) AS importados FROM projects
UNION ALL
SELECT 'project_samples',    COUNT(*) FROM project_samples
UNION ALL
SELECT 'project_apps',       COUNT(*) FROM project_applications
UNION ALL
SELECT 'project_evals',      COUNT(*) FROM project_evaluations
UNION ALL
SELECT 'project_marketing',  COUNT(*) FROM project_marketing
UNION ALL
SELECT 'project_variants',   COUNT(*) FROM project_variants
UNION ALL
SELECT 'project_requests',   COUNT(*) FROM project_requests
UNION ALL
SELECT 'project_fragrances', COUNT(*) FROM project_fragrances;
