-- =============================================================================
-- 01_catalogos.sql
-- Migra catálogos de fragancias del legacy al nuevo backend.
-- Requiere que el dump legacy esté importado en la BD "legacy".
-- Usa INSERT IGNORE — no borra ni sobreescribe data existente.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------------------------
-- fragrance_houses ← legacy.casas
-- IDs preservados (fine_fragrances y fragrance_families los referencian)
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO fragrance_houses (id, nombre, created_at, updated_at)
SELECT
    id,
    CONVERT(nombre USING utf8mb4),
    NOW(),
    NOW()
FROM legacy.casas;

-- -----------------------------------------------------------------------------
-- fragrance_families ← legacy.general_fine_fragances
-- IDs preservados (fine_fragrances los referencia como family_id)
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO fragrance_families (id, nombre, familia_olfativa, nucleo, genero, casa_id, created_at, updated_at)
SELECT
    id,
    CONVERT(nombre USING utf8mb4),
    CONVERT(familia_olfativa USING utf8mb4),
    CONVERT(nucleo USING utf8mb4),
    genero,
    casa_id,
    NOW(),
    NOW()
FROM legacy.general_fine_fragances;

-- -----------------------------------------------------------------------------
-- fine_fragrances ← legacy.fine_fragances
-- IDs preservados (project_fragrances los referencia)
-- casa_id obtenido vía join con general_fine_fragances
-- precio_usd no existe en legacy → 0
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO fine_fragrances (id, nombre, codigo, precio, precio_usd, casa_id, family_id, created_at, updated_at)
SELECT
    ff.id,
    CONVERT(ff.nombre USING utf8mb4),
    ff.codigo,
    ff.precio / 1000.0,   -- legacy guarda precio en entero * 1000
    0,                     -- precio_usd no disponible en legacy
    gff.casa_id,
    ff.general_fine_fragances_id,
    NOW(),
    NOW()
FROM legacy.fine_fragances ff
JOIN legacy.general_fine_fragances gff ON gff.id = ff.general_fine_fragances_id;

-- -----------------------------------------------------------------------------
-- fragrances ← legacy.fragancias_coleccion
-- IDs preservados (project_requests los referencia como fragrance_id)
-- Los ~20 flags booleanos se consolidan en un JSON "usos"
-- codigo: usa cod_desarrollo del legacy
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO fragrances (id, nombre, referencia, codigo, precio, precio_usd, usos, created_at, updated_at)
SELECT
    f.id,
    CONVERT(f.nombre USING utf8mb4),
    CONVERT(f.referencia USING utf8mb4),
    CONVERT(f.cod_desarrollo USING utf8mb4),
    f.precio,
    f.precio_usd,
    JSON_OBJECT(
        'apc',              IF(f.apc = 1, true, false),
        'ambientacion',     IF(f.ambientacion = 1, true, false),
        'velas',            IF(f.velas = 1, true, false),
        'det_polvo',        IF(f.det_polvo = 1, true, false),
        'det_liquido',      IF(f.det_liquido = 1, true, false),
        'jabon_en_barra',   IF(f.jabon_en_barra = 1, true, false),
        'suavizante',       IF(f.suavizante = 1, true, false),
        'prot_color',       IF(f.prot_color = 1, true, false),
        'cera',             IF(f.cera = 1, true, false),
        'lavavajillas',     IF(f.lavavajillas = 1, true, false),
        'desengrasante',    IF(f.desengrasante = 1, true, false),
        'gourmand',         IF(f.gourmand = 1, true, false),
        'capilar',          IF(f.capilar = 1, true, false),
        'proteccion_solar', IF(f.proteccion_solar = 1, true, false),
        'corporal',         IF(f.corporal = 1, true, false),
        'manos',            IF(f.manos = 1, true, false),
        'splash',           IF(f.splash = 1, true, false),
        'tocador',          IF(f.tocador = 1, true, false),
        'liquido',          IF(f.liquido = 1, true, false),
        'desodorante',      IF(f.desodorante = 1, true, false),
        'bebes',            IF(f.bebes = 1, true, false)
    ),
    NOW(),
    NOW()
FROM legacy.fragancias_coleccion f;

-- -----------------------------------------------------------------------------
-- swissarom_references ← legacy.referencias_swissarom
-- Solo se migran los campos básicos que tiene la nueva tabla
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO swissarom_references (id, codigo, nombre, precio, created_at, updated_at)
SELECT
    id,
    CAST(codigo AS CHAR),
    CONVERT(nombre USING utf8mb4),
    precio,
    NOW(),
    NOW()
FROM legacy.referencias_swissarom;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'catalogos OK' AS resultado;
