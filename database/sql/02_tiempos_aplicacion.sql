-- =============================================================================
-- 02_tiempos_aplicacion.sql
-- Migra tiempos_aplicacion del legacy a time_applications.
-- Requiere que el dump legacy esté importado en la BD "legacy".
-- Requiere que project_product_types ya esté poblado (seeder ProjectTimesSeeder).
-- Usa INSERT IGNORE — no borra ni sobreescribe data existente.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Limpia la tabla antes de importar (son datos de lookup, no transaccionales)
TRUNCATE TABLE time_applications;

-- tipo_cliente: A→pareto, B→balance, C→none, D→none
-- product_id: mismo ID que en project_product_types (IDs preservados del legacy)
-- rango_min/rango_max son INT en legacy → DECIMAL en nuevo (compatible)
INSERT INTO time_applications (tipo_cliente, rango_min, rango_max, product_id, valor, created_at, updated_at)
SELECT
    CASE ta.tipo_cliente
        WHEN 'A' THEN 'pareto'
        WHEN 'B' THEN 'balance'
        WHEN 'C' THEN 'none'
        WHEN 'D' THEN 'none'
        ELSE 'none'
    END AS tipo_cliente,
    ta.rango_min,
    LEAST(ta.rango_max, 99999999),   -- cap: decimal(10,2) max = 99999999.99
    ta.producto_id,
    ta.valor,
    NOW(),
    NOW()
FROM legacy.tiempos_aplicacion ta
-- Solo importar donde el product_id existe en project_product_types
WHERE ta.producto_id IN (SELECT id FROM project_product_types);

SET FOREIGN_KEY_CHECKS = 1;

SELECT CONCAT('time_applications importados: ', COUNT(*)) AS resultado FROM time_applications;
