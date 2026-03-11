-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1:3306
-- Tiempo de generación: 11-03-2026 a las 12:24:14
-- Versión del servidor: 11.4.2-MariaDB
-- Versión de PHP: 8.3.9

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `finearom`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `branch_offices`
--

CREATE TABLE `branch_offices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `nit` varchar(255) NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `delivery_address` text NOT NULL,
  `delivery_city` varchar(255) NOT NULL,
  `general_observations` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `main_function` varchar(500) DEFAULT NULL COMMENT 'Función principal de la sucursal (Producción, Ventas, etc.)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cartera`
--

CREATE TABLE `cartera` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nit` varchar(20) NOT NULL,
  `ciudad` varchar(100) NOT NULL,
  `vendedor` varchar(50) NOT NULL,
  `nombre_vendedor` varchar(255) NOT NULL,
  `cuenta` varchar(50) NOT NULL,
  `descripcion_cuenta` varchar(255) NOT NULL,
  `documento` varchar(50) NOT NULL DEFAULT '0',
  `fecha` date NOT NULL,
  `fecha_from` date NOT NULL DEFAULT current_timestamp(),
  `fecha_to` date NOT NULL DEFAULT current_timestamp(),
  `fecha_cartera` date NOT NULL,
  `vence` date NOT NULL,
  `dias` int(10) NOT NULL,
  `saldo_contable` varchar(255) NOT NULL DEFAULT '',
  `vencido` varchar(255) NOT NULL DEFAULT '',
  `saldo_vencido` varchar(255) NOT NULL DEFAULT '',
  `nombre_empresa` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `catera_type` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categories`
--

CREATE TABLE `categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `category_type_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `weight` int(11) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `category_types`
--

CREATE TABLE `category_types` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `machine_name` varchar(64) NOT NULL,
  `is_flat` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clients`
--

CREATE TABLE `clients` (
  `id` bigint(20) UNSIGNED NOT NULL COMMENT 'ID único del cliente',
  `client_name` varchar(255) NOT NULL COMMENT 'Nombre del cliente',
  `nit` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Nit',
  `created_at` timestamp NULL DEFAULT NULL COMMENT 'Fecha de creación',
  `updated_at` timestamp NULL DEFAULT NULL COMMENT 'Fecha de última actualización',
  `client_type` varchar(255) NOT NULL COMMENT 'Tipo de cliente - Pareto - none',
  `lead_time` int(11) DEFAULT NULL,
  `first_dispatch_date` date DEFAULT NULL,
  `estimated_launch_date` date DEFAULT NULL,
  `first_dispatch_quantity` decimal(10,2) DEFAULT NULL,
  `purchase_frequency` varchar(255) DEFAULT NULL,
  `estimated_monthly_quantity` decimal(10,2) DEFAULT NULL,
  `product_category` varchar(255) DEFAULT NULL,
  `email` text DEFAULT NULL COMMENT 'Correo electrónico principal',
  `catera_emnail` text DEFAULT NULL COMMENT 'Email de cartera',
  `executive` varchar(255) DEFAULT NULL COMMENT 'Ejecutivo asignado',
  `accounting_contact_email` varchar(255) DEFAULT NULL COMMENT 'Email del contacto contable',
  `executive_email` varchar(255) DEFAULT NULL COMMENT 'Email del ejecutivo',
  `registration_address` varchar(255) DEFAULT NULL COMMENT 'Dirección de registro',
  `shipping_notes` text DEFAULT NULL COMMENT 'Notas de envío',
  `registration_city` varchar(255) DEFAULT NULL COMMENT 'Ciudad de registro',
  `accounting_contact` text DEFAULT NULL COMMENT 'Contacto contable',
  `commercial_terms` text DEFAULT NULL COMMENT 'Términos comerciales',
  `dispatch_confirmation_email` varchar(255) DEFAULT NULL COMMENT 'Email confirmación de despacho',
  `dispatch_confirmation_contact` varchar(255) DEFAULT NULL COMMENT 'Contacto confirmación de despacho',
  `trm` varchar(255) DEFAULT NULL COMMENT 'TRM (Tasa Representativa del Mercado)',
  `address` varchar(255) DEFAULT NULL COMMENT 'Dirección',
  `city` varchar(255) DEFAULT NULL COMMENT 'Ciudad',
  `phone` varchar(255) DEFAULT NULL COMMENT 'Teléfono',
  `billing_closure` varchar(255) DEFAULT NULL COMMENT 'Cierre de facturación',
  `commercial_conditions` text DEFAULT NULL COMMENT 'Condiciones comerciales',
  `proforma_invoice` tinyint(1) DEFAULT 0 COMMENT 'Factura proforma (0=No, 1=Sí)',
  `payment_type` varchar(255) DEFAULT NULL COMMENT 'Tipo de pago',
  `operation_type` varchar(255) DEFAULT NULL COMMENT 'Tipo de operación - extranjero, nacional',
  `compras_email` text DEFAULT NULL COMMENT 'Email de compras',
  `logistics_email` text DEFAULT NULL COMMENT 'Email de logística',
  `rut_file` varchar(255) DEFAULT NULL COMMENT 'Archivo RUT',
  `camara_comercio_file` varchar(255) DEFAULT NULL COMMENT 'Archivo cámara de comercio',
  `cedula_representante_file` varchar(255) DEFAULT NULL COMMENT 'Archivo cédula del representante',
  `requires_study` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Requiere estudio (0=No, 1=Sí)',
  `declaracion_renta_file` varchar(255) DEFAULT NULL COMMENT 'Archivo declaración de renta',
  `estados_financieros_file` varchar(255) DEFAULT NULL COMMENT 'Archivo estados financieros',
  `drive_doc_links` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`drive_doc_links`)),
  `drive_folder_link` varchar(255) DEFAULT NULL,
  `payment_method` tinyint(4) DEFAULT NULL COMMENT 'Método de pago: Contado = 1, Credito: 2',
  `payment_day` int(11) DEFAULT NULL COMMENT 'Día de pago',
  `user_id` int(11) DEFAULT NULL COMMENT 'ID del usuario creador',
  `status` enum('active','inactive') DEFAULT 'active' COMMENT 'Estado del cliente: inactive, active',
  `temporal_mark` varchar(255) DEFAULT NULL COMMENT 'Marca temporal',
  `business_name` varchar(255) DEFAULT NULL COMMENT 'Razón social',
  `country` varchar(255) DEFAULT NULL COMMENT 'País',
  `is_in_free_zone` tinyint(1) DEFAULT 0 COMMENT 'Está en zona franca (0=No, 1=Sí)',
  `purchasing_contact_name` varchar(255) DEFAULT NULL COMMENT 'Nombre contacto de compras',
  `purchasing_contact_phone` varchar(255) DEFAULT NULL COMMENT 'Teléfono contacto de compras',
  `purchasing_contact_email` varchar(255) DEFAULT NULL COMMENT 'Email contacto de compras',
  `logistics_contact_name` varchar(255) DEFAULT NULL COMMENT 'Nombre contacto de logística',
  `logistics_contact_phone` varchar(255) DEFAULT NULL COMMENT 'Teléfono contacto de logística',
  `dispatch_conditions` text DEFAULT NULL COMMENT 'Condiciones de despacho',
  `billing_contact_name` varchar(255) DEFAULT NULL COMMENT 'Nombre contacto de facturación',
  `billing_contact_phone` varchar(255) DEFAULT NULL COMMENT 'Teléfono contacto de facturación',
  `billing_closure_date` date DEFAULT NULL COMMENT 'Fecha de cierre de facturación',
  `taxpayer_type` varchar(255) DEFAULT NULL COMMENT 'Tipo de contribuyente',
  `credit_term` int(11) DEFAULT NULL COMMENT 'Término de crédito (días)',
  `portfolio_contact_name` varchar(255) DEFAULT NULL COMMENT 'Nombre contacto de cartera',
  `portfolio_contact_phone` varchar(255) DEFAULT NULL COMMENT 'Teléfono contacto de cartera',
  `portfolio_contact_email` varchar(255) DEFAULT NULL COMMENT 'Email contacto de cartera',
  `r_and_d_contact_name` varchar(255) DEFAULT NULL COMMENT 'Nombre contacto I+D',
  `r_and_d_contact_phone` varchar(255) DEFAULT NULL COMMENT 'Teléfono contacto I+D',
  `r_and_d_contact_email` varchar(255) DEFAULT NULL COMMENT 'Email contacto I+D',
  `data_consent` tinyint(1) DEFAULT 0 COMMENT 'Consentimiento de datos (0=No, 1=Sí)',
  `marketing_consent` tinyint(1) DEFAULT 0 COMMENT 'Consentimiento de marketing (0=No, 1=Sí)',
  `iva` decimal(10,2) DEFAULT NULL COMMENT 'Porcentaje de IVA',
  `retefuente` decimal(10,2) DEFAULT NULL COMMENT 'Porcentaje de retención en la fuente',
  `reteiva` decimal(10,2) DEFAULT NULL COMMENT 'Porcentaje de retención de IVA',
  `ica` decimal(10,2) DEFAULT NULL COMMENT 'Porcentaje de ICA',
  `tipo_contribuyente` varchar(255) DEFAULT NULL COMMENT 'Tipo de contribuyente- RESPONSABLE DE IVA, GRAN CONTRIBUYENTE NO RESIDENTE EN PAIS',
  `zona_franca` tinyint(1) DEFAULT NULL COMMENT 'Zona franca (0=No, 1=Sí)',
  `ciudad` varchar(255) DEFAULT NULL COMMENT 'Ciudad (alternativo)',
  `venta_de_contado` varchar(255) DEFAULT NULL COMMENT 'Venta de contado',
  `actualizado_proforma` tinyint(1) DEFAULT 0 COMMENT 'Proforma actualizada (0=No, 1=Sí)',
  `main_function` varchar(255) DEFAULT NULL COMMENT 'Función principal',
  `executive_phone` varchar(20) DEFAULT NULL COMMENT 'Teléfono del ejecutivo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `client_observations`
--

CREATE TABLE `client_observations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `requires_physical_invoice` tinyint(1) NOT NULL DEFAULT 0,
  `is_in_free_zone` tinyint(1) NOT NULL DEFAULT 0,
  `packaging_unit` int(11) DEFAULT NULL,
  `reteica` int(11) DEFAULT NULL,
  `reteiva` int(11) DEFAULT NULL,
  `retefuente` int(11) DEFAULT NULL,
  `requires_appointment` tinyint(1) NOT NULL DEFAULT 0,
  `additional_observations` text DEFAULT NULL,
  `billing_closure_date` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `config_systems`
--

CREATE TABLE `config_systems` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(255) NOT NULL,
  `type` varchar(50) NOT NULL,
  `value` longtext DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `email_campaigns`
--

CREATE TABLE `email_campaigns` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `campaign_name` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `email_field_type` varchar(255) NOT NULL,
  `body` longtext NOT NULL,
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`attachments`)),
  `client_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`client_ids`)),
  `custom_emails` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`custom_emails`)),
  `status` varchar(255) NOT NULL DEFAULT 'draft',
  `total_recipients` int(11) NOT NULL DEFAULT 0,
  `sent_count` int(11) NOT NULL DEFAULT 0,
  `failed_count` int(11) NOT NULL DEFAULT 0,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `email_campaign_logs`
--

CREATE TABLE `email_campaign_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email_campaign_id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `email_field_used` varchar(255) NOT NULL,
  `email_sent_to` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `open_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `email_dispatch_queues`
--

CREATE TABLE `email_dispatch_queues` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `client_nit` varchar(30) NOT NULL,
  `due_date` date NOT NULL,
  `order_block_notification_emails` text DEFAULT NULL,
  `outstanding_balance_notification_emails` text DEFAULT NULL,
  `email_type` varchar(50) NOT NULL,
  `send_status` enum('pending','sent','failed','sending','queued') DEFAULT 'pending',
  `retry_count` tinyint(3) UNSIGNED DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `email_sent_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `queued_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `email_logs`
--

CREATE TABLE `email_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `sender_email` varchar(255) DEFAULT NULL,
  `recipient_email` text NOT NULL,
  `subject` longtext DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `process_type` varchar(255) NOT NULL DEFAULT 'system',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `error_message` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `open_count` int(11) NOT NULL DEFAULT 0,
  `ip_address` varchar(255) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `email_templates`
--

CREATE TABLE `email_templates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `key` varchar(255) NOT NULL COMMENT 'Identificador único del template (ej: client_welcome, purchase_order_update)',
  `name` varchar(255) NOT NULL COMMENT 'Nombre descriptivo del template',
  `subject` varchar(255) NOT NULL COMMENT 'Asunto del email',
  `title` varchar(255) DEFAULT NULL COMMENT 'Título con estilo especial que aparece en el email',
  `header_content` text DEFAULT NULL COMMENT 'Contenido HTML del header (editable con CKEditor)',
  `footer_content` text DEFAULT NULL COMMENT 'Contenido HTML del footer (editable con CKEditor)',
  `signature` text DEFAULT NULL COMMENT 'Firma HTML (editable con CKEditor)',
  `available_variables` text DEFAULT NULL COMMENT 'JSON con las variables disponibles para este template',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `executives`
--

CREATE TABLE `executives` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL COMMENT 'Nombre completo del ejecutivo',
  `email` varchar(255) NOT NULL COMMENT 'Email del ejecutivo',
  `phone` varchar(50) DEFAULT NULL COMMENT 'Teléfono del ejecutivo',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Si el ejecutivo está activo',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de ejecutivos de cuenta';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `finearom_price_history`
--

CREATE TABLE `finearom_price_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `finearom_reference_id` bigint(20) UNSIGNED NOT NULL,
  `precio_anterior` decimal(10,2) DEFAULT NULL,
  `precio_nuevo` decimal(10,2) NOT NULL,
  `changed_by` varchar(200) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `finearom_references`
--

CREATE TABLE `finearom_references` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `codigo` varchar(255) DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `precio` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fine_fragrances`
--

CREATE TABLE `fine_fragrances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `codigo` varchar(255) DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL DEFAULT 0.00,
  `precio_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
  `casa_id` bigint(20) UNSIGNED DEFAULT NULL,
  `family_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fragrances`
--

CREATE TABLE `fragrances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `referencia` varchar(255) DEFAULT NULL,
  `codigo` varchar(255) DEFAULT NULL,
  `precio` decimal(10,2) NOT NULL DEFAULT 0.00,
  `precio_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
  `usos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`usos`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fragrance_families`
--

CREATE TABLE `fragrance_families` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `familia_olfativa` varchar(255) DEFAULT NULL,
  `nucleo` varchar(255) DEFAULT NULL,
  `genero` varchar(255) DEFAULT NULL,
  `casa_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `fragrance_houses`
--

CREATE TABLE `fragrance_houses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `group_classifications`
--

CREATE TABLE `group_classifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `rango_min` decimal(10,2) NOT NULL,
  `rango_max` decimal(10,2) NOT NULL,
  `tipo_cliente` enum('pareto','balance','none') NOT NULL,
  `valor` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ia_forecast_batch_runs`
--

CREATE TABLE `ia_forecast_batch_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `mode` varchar(40) NOT NULL DEFAULT 'PROCESS_ALL',
  `status` varchar(40) NOT NULL DEFAULT 'QUEUED',
  `total_clientes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `total_productos` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `procesados` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `completados` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `errores` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `pendientes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `creado_por` bigint(20) UNSIGNED DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ia_forecast_batch_run_items`
--

CREATE TABLE `ia_forecast_batch_run_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `batch_run_id` bigint(20) UNSIGNED NOT NULL,
  `cliente_id` bigint(20) UNSIGNED NOT NULL,
  `client_name` varchar(255) DEFAULT NULL,
  `total_productos` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `current_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'PENDING',
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ia_forecast_client_runs`
--

CREATE TABLE `ia_forecast_client_runs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `cliente_id` bigint(20) UNSIGNED NOT NULL,
  `batch_run_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'QUEUED',
  `total_productos` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `procesados` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `completados` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `errores` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `pendientes` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `creado_por` bigint(20) UNSIGNED DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ia_forecast_client_run_items`
--

CREATE TABLE `ia_forecast_client_run_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `run_id` bigint(20) UNSIGNED NOT NULL,
  `cliente_id` bigint(20) UNSIGNED NOT NULL,
  `producto_id` bigint(20) UNSIGNED NOT NULL,
  `sort_order` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `codigo` varchar(100) DEFAULT NULL,
  `producto` varchar(255) DEFAULT NULL,
  `kg_total` decimal(12,1) NOT NULL DEFAULT 0.0,
  `status` varchar(40) NOT NULL DEFAULT 'PENDING',
  `attempts` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `analizado_en` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ia_historial_mensual`
--

CREATE TABLE `ia_historial_mensual` (
  `id` bigint(20) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `mes` char(7) NOT NULL COMMENT 'YYYY-MM',
  `kg_real` decimal(12,3) NOT NULL DEFAULT 0.000,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ia_plan_compras`
--

CREATE TABLE `ia_plan_compras` (
  `id` bigint(20) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `codigo` varchar(50) DEFAULT NULL,
  `producto` varchar(255) DEFAULT NULL,
  `escenario` varchar(50) DEFAULT NULL,
  `tendencia` varchar(5) DEFAULT NULL,
  `tecnica` varchar(50) DEFAULT NULL,
  `prom_3m` decimal(10,2) DEFAULT NULL,
  `prom_6m` decimal(10,2) DEFAULT NULL,
  `prom_12m` decimal(10,2) DEFAULT NULL,
  `consistencia` tinyint(4) DEFAULT NULL,
  `meses` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT '4 meses de plan de compra' CHECK (json_valid(`meses`)),
  `alertas` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Alertas especificas del producto' CHECK (json_valid(`alertas`)),
  `analizado_en` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `jobs`
--

CREATE TABLE `jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) UNSIGNED NOT NULL,
  `reserved_at` int(10) UNSIGNED DEFAULT NULL,
  `available_at` int(10) UNSIGNED NOT NULL,
  `created_at` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `menus`
--

CREATE TABLE `menus` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `machine_name` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `menu_items`
--

CREATE TABLE `menu_items` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `menu_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `uri` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `parent_id` bigint(20) UNSIGNED DEFAULT NULL,
  `weight` int(11) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `icon` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `model_has_categories`
--

CREATE TABLE `model_has_categories` (
  `category_id` bigint(20) UNSIGNED NOT NULL,
  `category_item_type` varchar(255) DEFAULT NULL,
  `category_item_id` bigint(20) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `model_has_permissions`
--

CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `model_has_roles`
--

CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) UNSIGNED NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `order_google_task_configs`
--

CREATE TABLE `order_google_task_configs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `trigger` enum('on_create','on_observation','on_dispatch') NOT NULL,
  `user_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '[]' CHECK (json_valid(`user_ids`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `order_statistics`
--

CREATE TABLE `order_statistics` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL,
  `hour` tinyint(3) NOT NULL DEFAULT 23,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `total_orders_created` int(10) NOT NULL DEFAULT 0,
  `orders_pending` int(10) NOT NULL DEFAULT 0,
  `orders_processing` int(10) NOT NULL DEFAULT 0,
  `orders_completed` int(10) NOT NULL DEFAULT 0,
  `orders_parcial_status` int(10) NOT NULL DEFAULT 0,
  `orders_new_win` int(10) NOT NULL DEFAULT 0,
  `total_orders_value_usd` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_orders_value_cop` decimal(20,2) DEFAULT 0.00,
  `orders_commercial` int(10) NOT NULL DEFAULT 0,
  `orders_sample` int(10) NOT NULL DEFAULT 0,
  `orders_mixed` int(10) NOT NULL DEFAULT 0,
  `dispatched_orders_count` int(10) NOT NULL DEFAULT 0,
  `commercial_dispatched_value_usd` decimal(15,2) NOT NULL DEFAULT 0.00,
  `commercial_dispatched_value_cop` decimal(20,2) DEFAULT 0.00,
  `commercial_products_dispatched` int(10) NOT NULL DEFAULT 0,
  `sample_products_dispatched` int(10) NOT NULL DEFAULT 0,
  `commercial_partials_real` int(10) NOT NULL DEFAULT 0,
  `commercial_partials_temporal` int(10) NOT NULL DEFAULT 0,
  `sample_partials_real` int(10) NOT NULL DEFAULT 0,
  `sample_partials_temporal` int(10) NOT NULL DEFAULT 0,
  `orders_fully_dispatched` int(10) NOT NULL DEFAULT 0,
  `orders_partially_dispatched` int(10) NOT NULL DEFAULT 0,
  `orders_not_dispatched` int(10) NOT NULL DEFAULT 0,
  `pending_dispatch_value_usd` decimal(15,2) NOT NULL DEFAULT 0.00,
  `pending_dispatch_value_cop` decimal(20,2) DEFAULT 0.00,
  `avg_days_order_to_first_dispatch` decimal(5,2) NOT NULL DEFAULT 0.00,
  `dispatch_completion_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `average_trm` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `partials_with_default_trm` int(10) NOT NULL DEFAULT 0,
  `partials_with_custom_trm` int(10) NOT NULL DEFAULT 0,
  `unique_clients_with_orders` int(10) NOT NULL DEFAULT 0,
  `unique_clients_with_dispatches` int(10) NOT NULL DEFAULT 0,
  `planned_dispatch_value_usd` decimal(15,2) DEFAULT 0.00,
  `planned_dispatch_value_cop` decimal(20,2) DEFAULT 0.00,
  `planned_commercial_products` int(11) DEFAULT 0,
  `planned_sample_products` int(11) DEFAULT 0,
  `planned_orders_count` int(11) DEFAULT 0,
  `pending_commercial_products` int(11) DEFAULT 0,
  `pending_sample_products` int(11) DEFAULT 0,
  `dispatch_fulfillment_rate_usd` decimal(8,2) DEFAULT 0.00,
  `dispatch_fulfillment_rate_products` decimal(8,2) DEFAULT 0.00,
  `unique_clients_with_planned_dispatches` int(11) DEFAULT 0,
  `extended_stats` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`extended_stats`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `partials`
--

CREATE TABLE `partials` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `order_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` int(10) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'temporal',
  `trm` varchar(50) DEFAULT NULL,
  `pdf_invoice` varchar(50) DEFAULT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `tracking_number` text DEFAULT NULL,
  `transporter` varchar(50) DEFAULT NULL,
  `dispatch_date` date NOT NULL,
  `product_order_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `partial_logs`
--

CREATE TABLE `partial_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `partial_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_name` varchar(255) DEFAULT NULL,
  `action` enum('created','updated','deleted','restored') NOT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  `changed_fields` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`changed_fields`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `permissions`
--

CREATE TABLE `permissions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `processes`
--

CREATE TABLE `processes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `process_type` enum('orden_de_compra','confirmacion_despacho','pedido') NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `products`
--

CREATE TABLE `products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(255) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `categories` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`categories`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `product_categories`
--

CREATE TABLE `product_categories` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `product_discounts`
--

CREATE TABLE `product_discounts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `min_quantity` decimal(10,2) NOT NULL COMMENT 'Kilos mínimos para aplicar el descuento',
  `discount_percentage` decimal(5,2) NOT NULL COMMENT 'Porcentaje de descuento (ej: 5.00 = 5%)',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `product_price_history`
--

CREATE TABLE `product_price_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `effective_date` timestamp NOT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `projects`
--

CREATE TABLE `projects` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `prospect_id` bigint(20) UNSIGNED DEFAULT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `nombre_prospecto` varchar(300) DEFAULT NULL COMMENT 'Nombre del cliente cuando aún no existe en el sistema',
  `email_prospecto` varchar(255) DEFAULT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `tipo` enum('Colección','Desarrollo','Fine Fragances') NOT NULL,
  `rango_min` decimal(10,2) DEFAULT NULL,
  `rango_max` decimal(10,2) DEFAULT NULL,
  `volumen` decimal(10,2) DEFAULT NULL,
  `base_cliente` tinyint(1) NOT NULL DEFAULT 0,
  `proactivo` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_requerida` date DEFAULT NULL,
  `fecha_creacion` date NOT NULL,
  `fecha_calculada` date DEFAULT NULL,
  `fecha_entrega` date DEFAULT NULL,
  `dias_diferencia` int(11) DEFAULT NULL,
  `tipo_producto` varchar(255) DEFAULT NULL,
  `trm` decimal(10,2) DEFAULT NULL,
  `factor` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `homologacion` tinyint(1) NOT NULL DEFAULT 0,
  `internacional` tinyint(1) NOT NULL DEFAULT 0,
  `ejecutivo` varchar(255) DEFAULT NULL,
  `ejecutivo_id` bigint(20) UNSIGNED DEFAULT NULL,
  `estado_externo` enum('En espera','Ganado','Perdido') NOT NULL DEFAULT 'En espera',
  `razon_perdida` varchar(500) DEFAULT NULL,
  `fecha_externo` date DEFAULT NULL,
  `ejecutivo_externo` varchar(255) DEFAULT NULL,
  `estado_interno` enum('En proceso','Entregado') NOT NULL DEFAULT 'En proceso',
  `ejecutivo_interno` varchar(255) DEFAULT NULL,
  `estado_desarrollo` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_desarrollo` date DEFAULT NULL,
  `ejecutivo_desarrollo` varchar(255) DEFAULT NULL,
  `estado_laboratorio` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_laboratorio` date DEFAULT NULL,
  `ejecutivo_laboratorio` varchar(255) DEFAULT NULL,
  `estado_mercadeo` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_mercadeo` date DEFAULT NULL,
  `ejecutivo_mercadeo` varchar(255) DEFAULT NULL,
  `estado_calidad` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_calidad` date DEFAULT NULL,
  `ejecutivo_calidad` varchar(255) DEFAULT NULL,
  `estado_especiales` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_especiales` date DEFAULT NULL,
  `ejecutivo_especiales` varchar(255) DEFAULT NULL,
  `obs_lab` text DEFAULT NULL,
  `obs_des` text DEFAULT NULL,
  `obs_mer` text DEFAULT NULL,
  `obs_cal` text DEFAULT NULL,
  `obs_esp` text DEFAULT NULL,
  `obs_ext` text DEFAULT NULL,
  `actualizado` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_applications`
--

CREATE TABLE `project_applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `dosis` decimal(10,2) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_evaluations`
--

CREATE TABLE `project_evaluations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `tipos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tipos`)),
  `observacion` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_files`
--

CREATE TABLE `project_files` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `nombre_original` varchar(255) NOT NULL,
  `nombre_storage` varchar(255) NOT NULL,
  `path` varchar(255) NOT NULL,
  `mime_type` varchar(255) NOT NULL,
  `size` bigint(20) NOT NULL,
  `drive_file_id` varchar(255) DEFAULT NULL,
  `drive_link` varchar(255) DEFAULT NULL,
  `categoria` varchar(255) DEFAULT NULL,
  `ejecutivo` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_fragrances`
--

CREATE TABLE `project_fragrances` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `fine_fragrance_id` bigint(20) UNSIGNED NOT NULL,
  `gramos` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_google_task_configs`
--

CREATE TABLE `project_google_task_configs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `trigger` enum('on_create','on_status_change','near_deadline') NOT NULL,
  `user_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`user_ids`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_marketing`
--

CREATE TABLE `project_marketing` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `marketing` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`marketing`)),
  `calidad` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`calidad`)),
  `obs_marketing` text DEFAULT NULL,
  `obs_calidad` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_notifications`
--

CREATE TABLE `project_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `tipo` varchar(50) NOT NULL,
  `titulo` varchar(200) NOT NULL,
  `mensaje` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `leida_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_product_types`
--

CREATE TABLE `project_product_types` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `categoria` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_proposals`
--

CREATE TABLE `project_proposals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `variant_id` bigint(20) UNSIGNED NOT NULL,
  `finearom_reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `precio_snapshot` decimal(10,2) DEFAULT NULL COMMENT 'Precio de la referencia Swissarom al momento de crear la propuesta',
  `definitiva` tinyint(1) NOT NULL DEFAULT 0,
  `total_propuesta` decimal(10,2) DEFAULT NULL,
  `total_propuesta_cop` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_quotation_logs`
--

CREATE TABLE `project_quotation_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `version` tinyint(4) NOT NULL DEFAULT 1,
  `enviado_a` varchar(255) DEFAULT NULL,
  `ejecutivo` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_requests`
--

CREATE TABLE `project_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `fragrance_id` bigint(20) UNSIGNED DEFAULT NULL,
  `tipo` enum('coleccion','referencia') NOT NULL,
  `porcentaje` decimal(5,2) DEFAULT NULL,
  `nombre_asociado` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_samples`
--

CREATE TABLE `project_samples` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `cantidad` decimal(10,2) DEFAULT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_status_history`
--

CREATE TABLE `project_status_history` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `tipo` varchar(50) NOT NULL,
  `descripcion` varchar(500) NOT NULL,
  `ejecutivo` varchar(200) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `project_variants`
--

CREATE TABLE `project_variants` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `observaciones` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `prospects`
--

CREATE TABLE `prospects` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `nit` varchar(255) DEFAULT NULL,
  `tipo_cliente` enum('pareto','balance','none') NOT NULL DEFAULT 'none',
  `ejecutivo` varchar(255) DEFAULT NULL,
  `legacy_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `project_id` bigint(20) UNSIGNED DEFAULT NULL,
  `order_creation_date` date DEFAULT NULL,
  `required_delivery_date` date DEFAULT current_timestamp(),
  `order_consecutive` varchar(50) DEFAULT NULL,
  `observations` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` enum('pending','processing','completed','cancelled','parcial_status') DEFAULT 'pending',
  `invoice_number` varchar(255) DEFAULT NULL,
  `dispatch_date` date DEFAULT NULL,
  `tracking_number` varchar(255) DEFAULT NULL,
  `observations_extra` text DEFAULT NULL,
  `internal_observations` text DEFAULT NULL,
  `message_id` varchar(255) DEFAULT NULL,
  `subject` text DEFAULT NULL,
  `body` text DEFAULT NULL,
  `contact` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `trm` varchar(50) DEFAULT NULL,
  `trm_updated_at` date DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `invoice_pdf` varchar(255) DEFAULT NULL,
  `message_despacho_id` varchar(255) DEFAULT NULL,
  `tag_email_pedidos` text DEFAULT NULL,
  `tag_email_despachos` text DEFAULT NULL,
  `is_new_win` int(11) NOT NULL DEFAULT 0,
  `is_muestra` int(11) NOT NULL DEFAULT 0,
  `complete_observation` text DEFAULT NULL,
  `subject_client` varchar(255) NOT NULL,
  `subject_despacho` varchar(255) DEFAULT NULL,
  `subject_dispatch` varchar(255) DEFAULT NULL,
  `proforma_generada` tinyint(1) DEFAULT 0,
  `drive_file_id` varchar(255) DEFAULT NULL,
  `drive_link` varchar(255) DEFAULT NULL,
  `drive_attachment_links` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`drive_attachment_links`)),
  `sheets_exports` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`sheets_exports`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `purchase_order_comments`
--

CREATE TABLE `purchase_order_comments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_order_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `text` text NOT NULL,
  `type` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `purchase_order_product`
--

CREATE TABLE `purchase_order_product` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `purchase_order_id` bigint(20) UNSIGNED NOT NULL,
  `product_id` bigint(20) UNSIGNED NOT NULL,
  `quantity` decimal(8,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp(),
  `status` enum('inactive','active','processing') DEFAULT 'active',
  `branch_office_id` int(11) DEFAULT NULL,
  `new_win` int(11) NOT NULL DEFAULT 0,
  `new_win_date` date DEFAULT NULL,
  `muestra` int(11) NOT NULL DEFAULT 0,
  `cierre_cartera` date DEFAULT NULL,
  `parcial` varchar(255) DEFAULT NULL,
  `delivery_date` date NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `recaudos`
--

CREATE TABLE `recaudos` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `fecha_recaudo` datetime DEFAULT NULL COMMENT 'Fecha cuando se realizó el recaudo',
  `numero_recibo` varchar(50) NOT NULL COMMENT 'Número del recibo de pago',
  `fecha_vencimiento` datetime DEFAULT NULL COMMENT 'Fecha de vencimiento de la factura',
  `numero_factura` varchar(50) NOT NULL COMMENT 'Número de la factura',
  `nit` bigint(20) DEFAULT NULL COMMENT 'NIT del cliente',
  `cliente` varchar(255) NOT NULL COMMENT 'Nombre del cliente',
  `dias` int(11) DEFAULT 0 COMMENT 'Días de cartera (+ vencida, - anticipada)',
  `valor_cancelado` decimal(15,2) DEFAULT 0.00 COMMENT 'Valor cancelado en la transacción',
  `observaciones` text DEFAULT NULL COMMENT 'Observaciones adicionales del recaudo',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `roles`
--

CREATE TABLE `roles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `role_has_permissions`
--

CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` bigint(20) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siigo_cartera`
--

CREATE TABLE `siigo_cartera` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tipo_registro` varchar(5) DEFAULT NULL,
  `nit_tercero` varchar(20) DEFAULT NULL,
  `cuenta_contable` varchar(20) DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `descripcion` varchar(200) DEFAULT NULL,
  `tipo_mov` varchar(2) DEFAULT NULL,
  `siigo_hash` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siigo_clients`
--

CREATE TABLE `siigo_clients` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `nit` varchar(20) NOT NULL,
  `nombre` varchar(100) DEFAULT NULL,
  `tipo_doc` varchar(5) DEFAULT NULL,
  `numero_doc` varchar(20) DEFAULT NULL,
  `direccion` varchar(120) DEFAULT NULL,
  `ciudad` varchar(60) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `tipo_tercero` varchar(5) DEFAULT NULL,
  `siigo_codigo` varchar(20) DEFAULT NULL,
  `siigo_hash` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siigo_movements`
--

CREATE TABLE `siigo_movements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tipo_comprobante` varchar(10) DEFAULT NULL,
  `numero_doc` varchar(20) DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `nit_tercero` varchar(20) DEFAULT NULL,
  `cuenta_contable` varchar(20) DEFAULT NULL,
  `descripcion` varchar(200) DEFAULT NULL,
  `valor` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tipo_mov` varchar(2) DEFAULT NULL,
  `siigo_hash` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siigo_products`
--

CREATE TABLE `siigo_products` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `codigo` varchar(30) NOT NULL,
  `nombre` varchar(120) DEFAULT NULL,
  `precio` decimal(15,2) NOT NULL DEFAULT 0.00,
  `unidad_medida` varchar(10) DEFAULT NULL,
  `grupo` varchar(30) DEFAULT NULL,
  `siigo_hash` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siigo_sync_logs`
--

CREATE TABLE `siigo_sync_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `file_name` varchar(20) NOT NULL,
  `action` varchar(20) NOT NULL,
  `records_count` int(11) NOT NULL DEFAULT 0,
  `details` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siigo_webhook_logs`
--

CREATE TABLE `siigo_webhook_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event` varchar(50) NOT NULL,
  `source_ip` varchar(45) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `status` varchar(20) NOT NULL DEFAULT 'received',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `time_applications`
--

CREATE TABLE `time_applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `rango_min` decimal(10,2) NOT NULL,
  `rango_max` decimal(10,2) NOT NULL,
  `tipo_cliente` enum('pareto','balance','none') NOT NULL,
  `product_id` bigint(20) UNSIGNED DEFAULT NULL,
  `valor` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `time_evaluations`
--

CREATE TABLE `time_evaluations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `grupo` int(11) NOT NULL,
  `solicitud` varchar(255) NOT NULL,
  `valor` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `time_fine`
--

CREATE TABLE `time_fine` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `num_fragrances_min` int(11) NOT NULL,
  `num_fragrances_max` int(11) NOT NULL,
  `tipo_cliente` enum('pareto','balance','none') NOT NULL,
  `valor` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `time_homologations`
--

CREATE TABLE `time_homologations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `num_variantes_min` int(11) NOT NULL,
  `num_variantes_max` int(11) NOT NULL,
  `grupo` int(11) NOT NULL,
  `valor` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `time_marketing`
--

CREATE TABLE `time_marketing` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `grupo` int(11) NOT NULL,
  `solicitud` varchar(255) NOT NULL,
  `valor` decimal(8,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `time_quality`
--

CREATE TABLE `time_quality` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `grupo` int(11) NOT NULL,
  `solicitud` varchar(255) NOT NULL,
  `valor` decimal(8,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `time_responses`
--

CREATE TABLE `time_responses` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `num_variantes_min` int(11) NOT NULL,
  `num_variantes_max` int(11) NOT NULL,
  `grupo` int(11) NOT NULL,
  `valor` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `time_samples`
--

CREATE TABLE `time_samples` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `rango_min` decimal(10,2) NOT NULL,
  `rango_max` decimal(10,2) NOT NULL,
  `tipo_cliente` enum('pareto','balance','none') NOT NULL,
  `valor` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `trm_daily`
--

CREATE TABLE `trm_daily` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `date` date NOT NULL COMMENT 'Fecha de la TRM',
  `value` decimal(10,4) NOT NULL COMMENT 'Valor de la TRM',
  `source` varchar(50) NOT NULL DEFAULT 'soap' COMMENT 'Fuente de datos (soap, api)',
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Datos adicionales del servicio' CHECK (json_valid(`metadata`)),
  `is_weekend` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Si la fecha es fin de semana',
  `is_holiday` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Si la fecha es festivo',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `user_google_tokens`
--

CREATE TABLE `user_google_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text NOT NULL,
  `expires_at` timestamp NOT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scopes`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `branch_offices`
--
ALTER TABLE `branch_offices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`);

--
-- Indices de la tabla `cartera`
--
ALTER TABLE `cartera`
  ADD PRIMARY KEY (`id`) USING BTREE;

--
-- Indices de la tabla `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `categories_category_type_id_slug_unique` (`category_type_id`,`slug`);

--
-- Indices de la tabla `category_types`
--
ALTER TABLE `category_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_types_machine_name_unique` (`machine_name`);

--
-- Indices de la tabla `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `clients_nit_unique` (`nit`);

--
-- Indices de la tabla `client_observations`
--
ALTER TABLE `client_observations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_observations_client_id_foreign` (`client_id`);

--
-- Indices de la tabla `config_systems`
--
ALTER TABLE `config_systems`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `key` (`key`);

--
-- Indices de la tabla `email_campaigns`
--
ALTER TABLE `email_campaigns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email_campaigns_user_id_foreign` (`user_id`);

--
-- Indices de la tabla `email_campaign_logs`
--
ALTER TABLE `email_campaign_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `email_dispatch_queues`
--
ALTER TABLE `email_dispatch_queues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_client_nit` (`client_nit`),
  ADD KEY `idx_send_status` (`send_status`);

--
-- Indices de la tabla `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email_logs_uuid_unique` (`uuid`),
  ADD KEY `email_logs_recipient_email_index` (`recipient_email`(768)),
  ADD KEY `email_logs_process_type_index` (`process_type`),
  ADD KEY `email_logs_status_index` (`status`),
  ADD KEY `email_logs_created_at_index` (`created_at`);

--
-- Indices de la tabla `email_templates`
--
ALTER TABLE `email_templates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email_templates_key_unique` (`key`);

--
-- Indices de la tabla `executives`
--
ALTER TABLE `executives`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indices de la tabla `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indices de la tabla `finearom_price_history`
--
ALTER TABLE `finearom_price_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `swissarom_price_history_swissarom_reference_id_foreign` (`finearom_reference_id`);

--
-- Indices de la tabla `finearom_references`
--
ALTER TABLE `finearom_references`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `fine_fragrances`
--
ALTER TABLE `fine_fragrances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fine_fragrances_casa_id_foreign` (`casa_id`),
  ADD KEY `fine_fragrances_family_id_foreign` (`family_id`);

--
-- Indices de la tabla `fragrances`
--
ALTER TABLE `fragrances`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `fragrance_families`
--
ALTER TABLE `fragrance_families`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fragrance_families_casa_id_foreign` (`casa_id`);

--
-- Indices de la tabla `fragrance_houses`
--
ALTER TABLE `fragrance_houses`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `group_classifications`
--
ALTER TABLE `group_classifications`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `ia_forecast_batch_runs`
--
ALTER TABLE `ia_forecast_batch_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ia_forecast_batch_runs_status_created_at_index` (`status`,`created_at`);

--
-- Indices de la tabla `ia_forecast_batch_run_items`
--
ALTER TABLE `ia_forecast_batch_run_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ia_forecast_batch_item_unique` (`batch_run_id`,`cliente_id`),
  ADD KEY `ia_forecast_batch_run_items_batch_run_id_status_index` (`batch_run_id`,`status`);

--
-- Indices de la tabla `ia_forecast_client_runs`
--
ALTER TABLE `ia_forecast_client_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ia_forecast_client_runs_cliente_id_status_index` (`cliente_id`,`status`),
  ADD KEY `ia_forecast_client_runs_cliente_id_created_at_index` (`cliente_id`,`created_at`),
  ADD KEY `ia_forecast_client_runs_batch_run_id_status_index` (`batch_run_id`,`status`);

--
-- Indices de la tabla `ia_forecast_client_run_items`
--
ALTER TABLE `ia_forecast_client_run_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ia_forecast_run_item_unique` (`run_id`,`producto_id`),
  ADD KEY `ia_forecast_client_run_items_run_id_status_index` (`run_id`,`status`),
  ADD KEY `ia_forecast_client_run_items_cliente_id_producto_id_index` (`cliente_id`,`producto_id`);

--
-- Indices de la tabla `ia_historial_mensual`
--
ALTER TABLE `ia_historial_mensual`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cli_prod_mes` (`cliente_id`,`producto_id`,`mes`),
  ADD KEY `idx_cliente_mes` (`cliente_id`,`mes`),
  ADD KEY `idx_producto` (`producto_id`);

--
-- Indices de la tabla `ia_plan_compras`
--
ALTER TABLE `ia_plan_compras`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cli_prod` (`cliente_id`,`producto_id`),
  ADD KEY `idx_cliente` (`cliente_id`),
  ADD KEY `idx_analizado` (`analizado_en`);

--
-- Indices de la tabla `jobs`
--
ALTER TABLE `jobs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `jobs_queue_index` (`queue`);

--
-- Indices de la tabla `menus`
--
ALTER TABLE `menus`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `menus_machine_name_unique` (`machine_name`);

--
-- Indices de la tabla `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `menu_items_menu_id_foreign` (`menu_id`);

--
-- Indices de la tabla `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `model_has_categories`
--
ALTER TABLE `model_has_categories`
  ADD KEY `model_has_categories_category_id_foreign` (`category_id`),
  ADD KEY `model_has_categories_category_item_type_category_item_id_index` (`category_item_type`,`category_item_id`);

--
-- Indices de la tabla `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  ADD KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indices de la tabla `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  ADD KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`);

--
-- Indices de la tabla `order_google_task_configs`
--
ALTER TABLE `order_google_task_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_google_task_configs_trigger_unique` (`trigger`);

--
-- Indices de la tabla `order_statistics`
--
ALTER TABLE `order_statistics`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `date` (`date`) USING BTREE,
  ADD KEY `idx_date` (`date`) USING BTREE,
  ADD KEY `idx_created_at` (`created_at`) USING BTREE,
  ADD KEY `idx_updated_at` (`updated_at`) USING BTREE;

--
-- Indices de la tabla `partials`
--
ALTER TABLE `partials`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD KEY `partials_product_id_foreign` (`product_id`) USING BTREE,
  ADD KEY `partials_order_id_foreign` (`order_id`) USING BTREE,
  ADD KEY `idx_partials_type_dispatch_date` (`type`,`dispatch_date`),
  ADD KEY `idx_partials_product_order_id` (`product_order_id`),
  ADD KEY `idx_partials_product_id` (`product_id`),
  ADD KEY `idx_partials_order_id` (`order_id`),
  ADD KEY `idx_partials_complex_filter` (`type`,`dispatch_date`,`product_order_id`),
  ADD KEY `idx_partials_fk_product_order` (`product_order_id`),
  ADD KEY `idx_partials_fk_product` (`product_id`),
  ADD KEY `idx_partials_fk_order` (`order_id`);

--
-- Indices de la tabla `partial_logs`
--
ALTER TABLE `partial_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `partial_logs_partial_id_created_at_index` (`partial_id`,`created_at`),
  ADD KEY `partial_logs_user_id_created_at_index` (`user_id`,`created_at`),
  ADD KEY `partial_logs_action_created_at_index` (`action`,`created_at`),
  ADD KEY `partial_logs_created_at_index` (`created_at`),
  ADD KEY `partial_logs_action_index` (`action`);

--
-- Indices de la tabla `password_resets`
--
ALTER TABLE `password_resets`
  ADD KEY `password_resets_email_index` (`email`);

--
-- Indices de la tabla `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indices de la tabla `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indices de la tabla `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indices de la tabla `processes`
--
ALTER TABLE `processes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_process_type` (`process_type`);

--
-- Indices de la tabla `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `products_client_id_foreign` (`client_id`);

--
-- Indices de la tabla `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `product_categories_slug_unique` (`slug`);

--
-- Indices de la tabla `product_discounts`
--
ALTER TABLE `product_discounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_discounts_product_id_index` (`product_id`);

--
-- Indices de la tabla `product_price_history`
--
ALTER TABLE `product_price_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_price_history_created_by_foreign` (`created_by`),
  ADD KEY `product_price_history_product_id_effective_date_index` (`product_id`,`effective_date`);

--
-- Indices de la tabla `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `projects_legacy_id_unique` (`legacy_id`),
  ADD KEY `projects_client_id_foreign` (`client_id`),
  ADD KEY `projects_product_id_foreign` (`product_id`),
  ADD KEY `projects_ejecutivo_id_foreign` (`ejecutivo_id`),
  ADD KEY `projects_prospect_id_foreign` (`prospect_id`);

--
-- Indices de la tabla `project_applications`
--
ALTER TABLE `project_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_applications_project_id_foreign` (`project_id`);

--
-- Indices de la tabla `project_evaluations`
--
ALTER TABLE `project_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_evaluations_project_id_foreign` (`project_id`);

--
-- Indices de la tabla `project_files`
--
ALTER TABLE `project_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_files_project_id_foreign` (`project_id`);

--
-- Indices de la tabla `project_fragrances`
--
ALTER TABLE `project_fragrances`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_fragrances_project_id_foreign` (`project_id`),
  ADD KEY `project_fragrances_fine_fragrance_id_foreign` (`fine_fragrance_id`);

--
-- Indices de la tabla `project_google_task_configs`
--
ALTER TABLE `project_google_task_configs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `project_google_task_configs_project_id_trigger_unique` (`project_id`,`trigger`);

--
-- Indices de la tabla `project_marketing`
--
ALTER TABLE `project_marketing`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_marketing_project_id_foreign` (`project_id`);

--
-- Indices de la tabla `project_notifications`
--
ALTER TABLE `project_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_notifications_project_id_foreign` (`project_id`),
  ADD KEY `project_notifications_user_id_leida_at_index` (`user_id`,`leida_at`);

--
-- Indices de la tabla `project_product_types`
--
ALTER TABLE `project_product_types`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `project_proposals`
--
ALTER TABLE `project_proposals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_proposals_variant_id_foreign` (`variant_id`),
  ADD KEY `project_proposals_swissarom_reference_id_foreign` (`finearom_reference_id`);

--
-- Indices de la tabla `project_quotation_logs`
--
ALTER TABLE `project_quotation_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_quotation_logs_project_id_foreign` (`project_id`);

--
-- Indices de la tabla `project_requests`
--
ALTER TABLE `project_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_requests_project_id_foreign` (`project_id`),
  ADD KEY `project_requests_fragrance_id_foreign` (`fragrance_id`);

--
-- Indices de la tabla `project_samples`
--
ALTER TABLE `project_samples`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_samples_project_id_foreign` (`project_id`);

--
-- Indices de la tabla `project_status_history`
--
ALTER TABLE `project_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_status_history_project_id_foreign` (`project_id`);

--
-- Indices de la tabla `project_variants`
--
ALTER TABLE `project_variants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_variants_project_id_foreign` (`project_id`);

--
-- Indices de la tabla `prospects`
--
ALTER TABLE `prospects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `prospects_legacy_id_unique` (`legacy_id`);

--
-- Indices de la tabla `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_orders_client_id_foreign` (`client_id`),
  ADD KEY `idx_purchase_orders_status` (`status`),
  ADD KEY `idx_purchase_orders_client_id` (`client_id`),
  ADD KEY `idx_purchase_orders_status_client` (`status`,`client_id`),
  ADD KEY `idx_purchase_orders_created_at` (`created_at`),
  ADD KEY `idx_purchase_orders_updated_at` (`updated_at`),
  ADD KEY `purchase_orders_project_id_foreign` (`project_id`);

--
-- Indices de la tabla `purchase_order_comments`
--
ALTER TABLE `purchase_order_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_order_id` (`purchase_order_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indices de la tabla `purchase_order_product`
--
ALTER TABLE `purchase_order_product`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_order_products_purchase_order_id_foreign` (`purchase_order_id`),
  ADD KEY `purchase_order_products_client_id_foreign` (`product_id`) USING BTREE,
  ADD KEY `idx_purchase_order_product_muestra` (`muestra`),
  ADD KEY `idx_purchase_order_product_purchase_order_id` (`purchase_order_id`),
  ADD KEY `idx_purchase_order_product_product_id` (`product_id`);

--
-- Indices de la tabla `recaudos`
--
ALTER TABLE `recaudos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recaudos_numero_recibo` (`numero_recibo`),
  ADD KEY `idx_recaudos_numero_factura` (`numero_factura`),
  ADD KEY `idx_recaudos_nit` (`nit`),
  ADD KEY `idx_recaudos_cliente` (`cliente`),
  ADD KEY `idx_recaudos_fecha_recaudo` (`fecha_recaudo`),
  ADD KEY `idx_recaudos_recibo_factura` (`numero_recibo`,`numero_factura`),
  ADD KEY `idx_recaudos_fecha_cliente` (`fecha_recaudo`,`cliente`),
  ADD KEY `idx_recaudos_nit_fecha` (`nit`,`fecha_recaudo`);

--
-- Indices de la tabla `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`);

--
-- Indices de la tabla `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD PRIMARY KEY (`permission_id`,`role_id`),
  ADD KEY `role_has_permissions_role_id_foreign` (`role_id`);

--
-- Indices de la tabla `siigo_cartera`
--
ALTER TABLE `siigo_cartera`
  ADD PRIMARY KEY (`id`),
  ADD KEY `siigo_cartera_nit_tercero_index` (`nit_tercero`),
  ADD KEY `siigo_cartera_fecha_index` (`fecha`),
  ADD KEY `siigo_cartera_siigo_hash_index` (`siigo_hash`);

--
-- Indices de la tabla `siigo_clients`
--
ALTER TABLE `siigo_clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `siigo_clients_nit_unique` (`nit`);

--
-- Indices de la tabla `siigo_movements`
--
ALTER TABLE `siigo_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `siigo_movements_nit_tercero_fecha_index` (`nit_tercero`,`fecha`),
  ADD KEY `siigo_movements_tipo_comprobante_index` (`tipo_comprobante`);

--
-- Indices de la tabla `siigo_products`
--
ALTER TABLE `siigo_products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `siigo_products_codigo_unique` (`codigo`);

--
-- Indices de la tabla `siigo_sync_logs`
--
ALTER TABLE `siigo_sync_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `siigo_webhook_logs`
--
ALTER TABLE `siigo_webhook_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `siigo_webhook_logs_event_index` (`event`),
  ADD KEY `siigo_webhook_logs_created_at_index` (`created_at`);

--
-- Indices de la tabla `time_applications`
--
ALTER TABLE `time_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `time_applications_product_id_foreign` (`product_id`);

--
-- Indices de la tabla `time_evaluations`
--
ALTER TABLE `time_evaluations`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `time_fine`
--
ALTER TABLE `time_fine`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `time_homologations`
--
ALTER TABLE `time_homologations`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `time_marketing`
--
ALTER TABLE `time_marketing`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `time_quality`
--
ALTER TABLE `time_quality`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `time_responses`
--
ALTER TABLE `time_responses`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `time_samples`
--
ALTER TABLE `time_samples`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `trm_daily`
--
ALTER TABLE `trm_daily`
  ADD PRIMARY KEY (`id`) USING BTREE,
  ADD UNIQUE KEY `date` (`date`) USING BTREE,
  ADD UNIQUE KEY `trm_daily_date_unique` (`date`) USING BTREE,
  ADD KEY `trm_daily_date_index` (`date`) USING BTREE,
  ADD KEY `trm_daily_date_value_index` (`date`,`value`) USING BTREE;

--
-- Indices de la tabla `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- Indices de la tabla `user_google_tokens`
--
ALTER TABLE `user_google_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_google_tokens_user_id_unique` (`user_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `branch_offices`
--
ALTER TABLE `branch_offices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cartera`
--
ALTER TABLE `cartera`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `categories`
--
ALTER TABLE `categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `category_types`
--
ALTER TABLE `category_types`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `clients`
--
ALTER TABLE `clients`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID único del cliente';

--
-- AUTO_INCREMENT de la tabla `client_observations`
--
ALTER TABLE `client_observations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `config_systems`
--
ALTER TABLE `config_systems`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `email_campaigns`
--
ALTER TABLE `email_campaigns`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `email_campaign_logs`
--
ALTER TABLE `email_campaign_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `email_dispatch_queues`
--
ALTER TABLE `email_dispatch_queues`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `email_templates`
--
ALTER TABLE `email_templates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `executives`
--
ALTER TABLE `executives`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `finearom_price_history`
--
ALTER TABLE `finearom_price_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `finearom_references`
--
ALTER TABLE `finearom_references`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fine_fragrances`
--
ALTER TABLE `fine_fragrances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fragrances`
--
ALTER TABLE `fragrances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fragrance_families`
--
ALTER TABLE `fragrance_families`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `fragrance_houses`
--
ALTER TABLE `fragrance_houses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `group_classifications`
--
ALTER TABLE `group_classifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ia_forecast_batch_runs`
--
ALTER TABLE `ia_forecast_batch_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ia_forecast_batch_run_items`
--
ALTER TABLE `ia_forecast_batch_run_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ia_forecast_client_runs`
--
ALTER TABLE `ia_forecast_client_runs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ia_forecast_client_run_items`
--
ALTER TABLE `ia_forecast_client_run_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ia_historial_mensual`
--
ALTER TABLE `ia_historial_mensual`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `ia_plan_compras`
--
ALTER TABLE `ia_plan_compras`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `jobs`
--
ALTER TABLE `jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `menus`
--
ALTER TABLE `menus`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `order_google_task_configs`
--
ALTER TABLE `order_google_task_configs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `order_statistics`
--
ALTER TABLE `order_statistics`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `partials`
--
ALTER TABLE `partials`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `partial_logs`
--
ALTER TABLE `partial_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `processes`
--
ALTER TABLE `processes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `products`
--
ALTER TABLE `products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `product_discounts`
--
ALTER TABLE `product_discounts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `product_price_history`
--
ALTER TABLE `product_price_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `projects`
--
ALTER TABLE `projects`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_applications`
--
ALTER TABLE `project_applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_evaluations`
--
ALTER TABLE `project_evaluations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_files`
--
ALTER TABLE `project_files`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_fragrances`
--
ALTER TABLE `project_fragrances`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_google_task_configs`
--
ALTER TABLE `project_google_task_configs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_marketing`
--
ALTER TABLE `project_marketing`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_notifications`
--
ALTER TABLE `project_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_product_types`
--
ALTER TABLE `project_product_types`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_proposals`
--
ALTER TABLE `project_proposals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_quotation_logs`
--
ALTER TABLE `project_quotation_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_requests`
--
ALTER TABLE `project_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_samples`
--
ALTER TABLE `project_samples`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_status_history`
--
ALTER TABLE `project_status_history`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `project_variants`
--
ALTER TABLE `project_variants`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `prospects`
--
ALTER TABLE `prospects`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `purchase_order_comments`
--
ALTER TABLE `purchase_order_comments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `purchase_order_product`
--
ALTER TABLE `purchase_order_product`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `recaudos`
--
ALTER TABLE `recaudos`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `roles`
--
ALTER TABLE `roles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siigo_cartera`
--
ALTER TABLE `siigo_cartera`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siigo_clients`
--
ALTER TABLE `siigo_clients`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siigo_movements`
--
ALTER TABLE `siigo_movements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siigo_products`
--
ALTER TABLE `siigo_products`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siigo_sync_logs`
--
ALTER TABLE `siigo_sync_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siigo_webhook_logs`
--
ALTER TABLE `siigo_webhook_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `time_applications`
--
ALTER TABLE `time_applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `time_evaluations`
--
ALTER TABLE `time_evaluations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `time_fine`
--
ALTER TABLE `time_fine`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `time_homologations`
--
ALTER TABLE `time_homologations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `time_marketing`
--
ALTER TABLE `time_marketing`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `time_quality`
--
ALTER TABLE `time_quality`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `time_responses`
--
ALTER TABLE `time_responses`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `time_samples`
--
ALTER TABLE `time_samples`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `trm_daily`
--
ALTER TABLE `trm_daily`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `user_google_tokens`
--
ALTER TABLE `user_google_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `branch_offices`
--
ALTER TABLE `branch_offices`
  ADD CONSTRAINT `branch_offices_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `categories_category_type_id_foreign` FOREIGN KEY (`category_type_id`) REFERENCES `category_types` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `client_observations`
--
ALTER TABLE `client_observations`
  ADD CONSTRAINT `client_observations_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `email_campaigns`
--
ALTER TABLE `email_campaigns`
  ADD CONSTRAINT `email_campaigns_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `finearom_price_history`
--
ALTER TABLE `finearom_price_history`
  ADD CONSTRAINT `swissarom_price_history_swissarom_reference_id_foreign` FOREIGN KEY (`finearom_reference_id`) REFERENCES `finearom_references` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `fine_fragrances`
--
ALTER TABLE `fine_fragrances`
  ADD CONSTRAINT `fine_fragrances_casa_id_foreign` FOREIGN KEY (`casa_id`) REFERENCES `fragrance_houses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fine_fragrances_family_id_foreign` FOREIGN KEY (`family_id`) REFERENCES `fragrance_families` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `fragrance_families`
--
ALTER TABLE `fragrance_families`
  ADD CONSTRAINT `fragrance_families_casa_id_foreign` FOREIGN KEY (`casa_id`) REFERENCES `fragrance_houses` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `menu_items`
--
ALTER TABLE `menu_items`
  ADD CONSTRAINT `menu_items_menu_id_foreign` FOREIGN KEY (`menu_id`) REFERENCES `menus` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `model_has_categories`
--
ALTER TABLE `model_has_categories`
  ADD CONSTRAINT `model_has_categories_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `model_has_permissions`
--
ALTER TABLE `model_has_permissions`
  ADD CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `model_has_roles`
--
ALTER TABLE `model_has_roles`
  ADD CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `partials`
--
ALTER TABLE `partials`
  ADD CONSTRAINT `partials_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION,
  ADD CONSTRAINT `partials_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE NO ACTION;

--
-- Filtros para la tabla `partial_logs`
--
ALTER TABLE `partial_logs`
  ADD CONSTRAINT `partial_logs_partial_id_foreign` FOREIGN KEY (`partial_id`) REFERENCES `partials` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `partial_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `products_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `product_discounts`
--
ALTER TABLE `product_discounts`
  ADD CONSTRAINT `product_discounts_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `product_price_history`
--
ALTER TABLE `product_price_history`
  ADD CONSTRAINT `product_price_history_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `product_price_history_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `projects_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `projects_ejecutivo_id_foreign` FOREIGN KEY (`ejecutivo_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `projects_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `project_product_types` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `projects_prospect_id_foreign` FOREIGN KEY (`prospect_id`) REFERENCES `prospects` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `project_applications`
--
ALTER TABLE `project_applications`
  ADD CONSTRAINT `project_applications_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_evaluations`
--
ALTER TABLE `project_evaluations`
  ADD CONSTRAINT `project_evaluations_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_files`
--
ALTER TABLE `project_files`
  ADD CONSTRAINT `project_files_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_fragrances`
--
ALTER TABLE `project_fragrances`
  ADD CONSTRAINT `project_fragrances_fine_fragrance_id_foreign` FOREIGN KEY (`fine_fragrance_id`) REFERENCES `fine_fragrances` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `project_fragrances_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_google_task_configs`
--
ALTER TABLE `project_google_task_configs`
  ADD CONSTRAINT `project_google_task_configs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_marketing`
--
ALTER TABLE `project_marketing`
  ADD CONSTRAINT `project_marketing_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_notifications`
--
ALTER TABLE `project_notifications`
  ADD CONSTRAINT `project_notifications_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `project_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_proposals`
--
ALTER TABLE `project_proposals`
  ADD CONSTRAINT `project_proposals_swissarom_reference_id_foreign` FOREIGN KEY (`finearom_reference_id`) REFERENCES `finearom_references` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `project_proposals_variant_id_foreign` FOREIGN KEY (`variant_id`) REFERENCES `project_variants` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_quotation_logs`
--
ALTER TABLE `project_quotation_logs`
  ADD CONSTRAINT `project_quotation_logs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_requests`
--
ALTER TABLE `project_requests`
  ADD CONSTRAINT `project_requests_fragrance_id_foreign` FOREIGN KEY (`fragrance_id`) REFERENCES `fragrances` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `project_requests_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_samples`
--
ALTER TABLE `project_samples`
  ADD CONSTRAINT `project_samples_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_status_history`
--
ALTER TABLE `project_status_history`
  ADD CONSTRAINT `project_status_history_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `project_variants`
--
ALTER TABLE `project_variants`
  ADD CONSTRAINT `project_variants_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `purchase_orders_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_orders_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `purchase_order_comments`
--
ALTER TABLE `purchase_order_comments`
  ADD CONSTRAINT `purchase_order_comments_ibfk_1` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `purchase_order_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `purchase_order_product`
--
ALTER TABLE `purchase_order_product`
  ADD CONSTRAINT `purchase_order_products_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `role_has_permissions`
--
ALTER TABLE `role_has_permissions`
  ADD CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `time_applications`
--
ALTER TABLE `time_applications`
  ADD CONSTRAINT `time_applications_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `project_product_types` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `user_google_tokens`
--
ALTER TABLE `user_google_tokens`
  ADD CONSTRAINT `user_google_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
