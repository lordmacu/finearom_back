-- ═══════════════════════════════════════════════════════════════════════════
-- DDL de referencia para Vanna (RAG) — tablas más usadas por el chat de análisis
-- Fuente: contrastado contra las ~49 queries de referencia validadas en producción
-- (MonthlyReportController::buildExamplesBlock()) y las reglas de negocio de
-- MonthlyReportController::buildSystemPrompt().
--
-- ⚠ NOTA IMPORTANTE: DATABASE.md (documentación general del proyecto) describe un
-- esquema DIFERENTE y desactualizado para purchase_orders y partials (estados
-- 'confirmed'/'dispatched' en vez de 'processing'/'parcial_status'; partials con
-- columna purchase_order_id y type 'permanent' en vez de order_id y type 'real';
-- sin columnas invoice_number/tracking_number/transporter/trm). Este archivo usa
-- el esquema REAL confirmado por las queries de producción, no el de DATABASE.md,
-- porque es lo que debe coincidir con qsql.json y documentation.md.
--
-- Tablas relacionadas que las queries de referencia también usan pero que quedan
-- fuera del alcance de este archivo (no listadas en el Task 4): purchase_order_product,
-- executives, sales_forecasts, cartera, recaudos, branch_offices, product_price_history.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_name VARCHAR(255) NOT NULL,           -- usar c.client_name (NO c.name)
  nit VARCHAR(50) NOT NULL,                    -- se cruza con cartera.nit, sales_forecasts.nit, recaudos.nit (BIGINT, requiere CAST)
  executive VARCHAR(255),                      -- email de la ejecutiva asignada; datos sucios (typos, comas, genéricos)
                                                -- ⚠ SIEMPRE resolver por email contra executives.email, NUNCA filtrar por nombre
                                                -- ⚠ COLLATE utf8mb4_unicode_ci al comparar con executives.email (collation distinta)
  client_type VARCHAR(20),                     -- DOS clasificaciones en la misma columna:
                                                --   comercial (lead time): 'AA' > 'A' > 'B' > 'C'
                                                --   portafolio (legado):   'pareto' | 'balance' | 'none'
  country VARCHAR(50),                         -- código ISO ('CO','EC','PE','NI') o texto ('internacional')
  lead_time INT,                                -- días de entrega estándar (AA/A=9, C=12)
  status ENUM('active','inactive') DEFAULT 'active',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,                   -- soft delete; filtrar deleted_at IS NULL para clientes activos

  UNIQUE KEY uq_clients_nit (nit),
  INDEX idx_clients_executive (executive),
  INDEX idx_clients_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- ⚠ clients.executive es utf8mb4_general_ci; executives.email es utf8mb4_unicode_ci.

CREATE TABLE purchase_orders (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_consecutive VARCHAR(100) NOT NULL,     -- consecutivo único, ej. '2025-0142' o 'OC-2026-001'
  client_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','processing','parcial_status','completed','cancelled') DEFAULT 'pending',
                                                -- pending(Francy crea) -> processing(Marlon revisa/estima)
                                                -- -> parcial_status | completed (Alexa despacha real)
                                                -- cancelled: excluir siempre de métricas
  order_creation_date DATETIME NOT NULL,       -- fecha de CREACIÓN de la OC (no confundir con fecha de despacho)
  is_new_win TINYINT(1) DEFAULT 0,             -- 1 = OC entera de cliente nuevo (usar COUNT(DISTINCT ... po.id), nunca SUM)
  is_muestra TINYINT(1) DEFAULT 0,             -- 1 = OC entera es muestra (sin costo), excluir de totales
  required_delivery_date DATE NULL,            -- fecha que pidió originalmente el cliente; NO usar para on-time
  dispatch_date DATE NULL,                     -- usado en la cascada COALESCE(MIN(partials temporal), pop.delivery_date, po.dispatch_date)
  trm VARCHAR(20) NULL,                        -- string en BD; usar (po.trm + 0) para operar numéricamente
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,

  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_po_consecutive (order_consecutive),
  INDEX idx_po_client (client_id),
  INDEX idx_po_status (status),
  INDEX idx_po_creation_date (order_creation_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ⚠ order_creation_date BETWEEN solo aplica si se pregunta por órdenes CREADAS en un período.
--   Nunca usarlo cuando el filtro principal es por status (las órdenes activas pueden ser de cualquier fecha).

CREATE TABLE partials (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id BIGINT UNSIGNED NOT NULL,           -- ⚠⚠ FK a purchase_orders — se llama order_id, NO purchase_order_id
  product_order_id BIGINT UNSIGNED NOT NULL,   -- FK a purchase_order_product (la línea específica de producto)
  type VARCHAR(20) NOT NULL,                   -- 'temporal' (fecha estimada, Marlon) | 'real' (despacho real, Alexa)
                                                -- 'real' es la FUENTE DE VERDAD de lo facturado/despachado
  quantity DECIMAL(12,2) NOT NULL,             -- kilos DESPACHADOS (real) o ESTIMADOS (temporal); NO confundir con pop.quantity (pedido)
  dispatch_date DATE NOT NULL,                 -- fecha real de despacho (type='real') o fecha estimada (type='temporal')
  invoice_number VARCHAR(50) NULL,             -- solo type='real'
  tracking_number VARCHAR(50) NULL,            -- guía de transporte, solo type='real'
  transporter VARCHAR(100) NULL,               -- transportadora, solo type='real'
  trm VARCHAR(20) NULL,                        -- string; usar (par.trm + 0). Algunos valores vienen ×100 (ej. 370005
                                                -- en vez de 3700.05) — validar rango BETWEEN 3200 AND 10000, si es
                                                -- mayor a 10000 dividir /100. Fallback: trm_daily del día -> 4000.
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,                   -- ⚠⚠ SOFT DELETE + REEMPLAZO: al reconfirmar un despacho/estimado, el
                                                -- sistema borra (soft delete) TODOS los partials previos del mismo
                                                -- tipo para esa OC y crea los nuevos desde cero.
                                                -- REGLA ABSOLUTA: SIEMPRE filtrar deleted_at IS NULL en cualquier query.

  FOREIGN KEY (order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_order_id) REFERENCES purchase_order_product(id) ON DELETE CASCADE,
  INDEX idx_partials_order (order_id),
  INDEX idx_partials_product_order (product_order_id),
  INDEX idx_partials_type (type),
  INDEX idx_partials_dispatch_date (dispatch_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ⚠ Inflación de kilos pedidos: nunca calcular SUM(pop.quantity) en el mismo CTE que itera sobre
--   partials (una línea de OC puede tener N partials reales en cuotas). Agregar en CTEs separados.

CREATE TABLE products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,          -- el catálogo de productos es POR CLIENTE (no global)
  code VARCHAR(100) NOT NULL,                  -- ⚠ NO es único globalmente: puede repetirse para varios clientes
                                                --   y a veces duplicado dentro del MISMO cliente (mismo client_id+code
                                                --   en 2-3 filas). Al unir con sales_forecasts, deduplicar con
                                                --   ROW_NUMBER() OVER (PARTITION BY client_id, code ORDER BY id) y
                                                --   filtrar rn=1 (CTE product_ranked) — si no, se duplican kilos/valor.
  product_name VARCHAR(255) NOT NULL,
  price DECIMAL(15,2) NOT NULL DEFAULT 0,      -- precio base; el precio real de una línea de OC puede diferir (pop.price)
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,

  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  INDEX idx_products_client (client_id),
  INDEX idx_products_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- ⚠ products.code es utf8mb4_general_ci; sales_forecasts.codigo es utf8mb4_unicode_ci (requiere COLLATE al unir).

CREATE TABLE trm_daily (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  date DATE NOT NULL,                          -- fecha del valor de TRM (fuente: Banco de la República)
  value DECIMAL(10,2) NOT NULL,                -- valor de la TRM en pesos colombianos; umbral mínimo válido = 3200
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,

  UNIQUE KEY uq_trm_date (date),
  INDEX idx_trm_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Uso típico (fallback final de la cascada TRM):
--   COALESCE(NULLIF(par.trm válida,0), (SELECT td.value FROM trm_daily td
--     WHERE td.date <= par.dispatch_date AND td.value >= 3200 ORDER BY td.date DESC LIMIT 1), 4000)
