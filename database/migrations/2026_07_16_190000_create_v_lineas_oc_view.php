<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista v_lineas_oc — una fila por LÍNEA de orden de compra, con los kilos ya resueltos.
 *
 * POR QUÉ EXISTE:
 * El partial 'temporal' (la programación de Marlon) NO se borra cuando Alexa despacha:
 * 1.784 de 1.814 líneas de 2026 tienen temporal == real. Es decir, el dato miente por diseño —
 * un envío planeado que sigue vivo después de cumplirse. Toda consulta que lo toque TIENE que
 * acordarse de restar lo despachado, y ese olvido ya produjo el mismo error con tres nombres
 * distintos ("pendiente", "pipeline", "valor potencial"), inflando cifras hasta +58%.
 *
 * En vez de repetir la fórmula en cada query (y confiar en que nadie la olvide), se calcula UNA
 * vez aquí. Quien pregunte pide la columna y no puede equivocarse, sin importar qué palabra use.
 *
 * TRES CONCEPTOS DISTINTOS que aquí quedan explícitos (línea con 1000 pedidos, 600 programados,
 * 400 despachados):
 *   kg_pedido         = 1000  (lo que el cliente pidió)
 *   kg_programado     =  600  (lo que Marlon agendó)
 *   kg_despachado     =  400  (lo que Alexa sacó)
 *   kg_falta_oc       =  600  (1000 - 400) -> "cuánto falta por despachar de la OC"
 *   kg_pipeline       =  200  ( 600 - 400) -> "lo programado que aún no sale"
 *
 * NO filtra muestra ni canceladas a propósito: expone las columnas para que cada consulta
 * decida. Así sirve igual para el chat, el dashboard o un reporte nuevo.
 *
 * ⚠ LEFT JOIN a products (NO inner): hay 670 líneas (25.514 kg) cuyo product_id ya no existe
 * en el catálogo — productos borrados. Con INNER JOIN desaparecían en silencio, justo el tipo
 * de fuga que esta vista existe para evitar. Ninguna tiene despachos (0 kg reales), pero SÍ
 * son kilos pedidos que nunca salieron, así que cuentan para kg_falta_oc. Cuando no hay
 * producto, precio_usd cae a pop.price y, si tampoco hay, a 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_lineas_oc');
        DB::statement("
            CREATE VIEW v_lineas_oc AS
            SELECT
                pop.id                          AS pop_id,
                po.id                           AS order_id,
                po.client_id                    AS client_id,
                pop.product_id                  AS product_id,
                po.status                       AS order_status,
                po.order_creation_date          AS order_creation_date,
                pop.muestra                     AS muestra,
                pop.quantity                    AS kg_pedido,
                COALESCE(t.kg, 0)               AS kg_programado,
                COALESCE(r.kg, 0)               AS kg_despachado,
                GREATEST(pop.quantity - COALESCE(r.kg, 0), 0)      AS kg_falta_oc,
                GREATEST(COALESCE(t.kg, 0) - COALESCE(r.kg, 0), 0) AS kg_pipeline,
                COALESCE(NULLIF(pop.price, 0), p.price, 0)         AS precio_usd,
                t.fecha_estimada                AS fecha_estimada,
                r.primer_despacho               AS primer_despacho,
                r.ultimo_despacho               AS ultimo_despacho
            FROM purchase_order_product pop
            JOIN purchase_orders po ON po.id = pop.purchase_order_id
            LEFT JOIN products p    ON p.id  = pop.product_id
            LEFT JOIN (
                SELECT product_order_id, SUM(quantity) AS kg, MIN(dispatch_date) AS fecha_estimada
                FROM partials
                WHERE type = 'temporal' AND deleted_at IS NULL
                GROUP BY product_order_id
            ) t ON t.product_order_id = pop.id
            LEFT JOIN (
                SELECT product_order_id, SUM(quantity) AS kg,
                       MIN(dispatch_date) AS primer_despacho, MAX(dispatch_date) AS ultimo_despacho
                FROM partials
                WHERE type = 'real' AND deleted_at IS NULL
                GROUP BY product_order_id
            ) r ON r.product_order_id = pop.id
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_lineas_oc');
    }
};
