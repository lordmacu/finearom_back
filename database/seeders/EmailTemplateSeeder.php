<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\EmailTemplate;

class EmailTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $templates = [
            [
                'key' => 'client_welcome',
                'name' => 'Bienvenida a Cliente',
                'subject' => 'Bienvenido a FINEAROM',
                'title' => '¡Es un honor darte la bienvenida a FINEAROM!',
                'header_content' => '<p style="text-align:center; margin-top:20px;"><strong>Estimado/a |client_name|.</strong></p>',
                'footer_content' => '<div>
    Estamos encantados de convertirnos en un aliado estratégico en la perfumación exitosa de sus nuevos productos. Nuestro
    compromiso con la calidad, la innovación exclusiva y el servicio personalizado será siempre nuestro principal y único
    objetivo: agregar valor a sus marcas.
</div>

<p>Nos mueve el propósito de ser un proveedor integral. Nuestras propuestas están basadas en una exploración constante del mercado, sus tendencias, la visualización y concreción de oportunidades dentro de su consumidor objetivo.</p>
<br>
<p><strong>Para garantizar una comunicación efectiva y fluida, te compartimos los datos de contacto de nuestros equipos especializados:</strong></p>

<table style="width:100%; border-collapse:collapse; margin-top:12px; text-align:center; margin:auto; border:0;">
    <tr>
        <td style="padding:10px; border:0;">
            <img src="|base_url|/images/gestioncartera.jpg" alt="Gestión de Cartera" style="width:90px; height:auto; display:block; margin:0 auto 8px;">
            <p style="margin:0; text-align:center;">
                 <a href="mailto:facturacion@finearom.com">facturacion@finearom.com</a>
            </p>
        </td>

         <td style="padding:10px; border:0;">
            <img src="|base_url|/images/comercial.jpg" alt="Ejecutiva Comercial" style="width:90px; height:auto; display:block; margin:0 auto 8px;">
            <p style="margin:0; text-align:center;">
                <strong>Ejecutiva Comercial</strong><br>
                |executive_name|<br>
                |executive_phone|<br>
                |executive_email|
            </p>
        </td>
        <td style="padding:10px; border:0;">
            <img src="|base_url|/images/logistica.jpg" alt="Logística y Despachos" style="width:90px; height:auto; display:block; margin:0 auto 8px;">
            <p style="margin:0; text-align:center;">
                  <a href="mailto:analista.operaciones@finearom.com">analista.operaciones@finearom.com</a>
            </p>
        </td>
    </tr>
</table>

<p>Si tienes alguna duda, no dudes en contactarnos. Estamos aquí para ayudarte.</p>',
                'signature' => null,
                'available_variables' => [
                    'client_name' => 'Nombre del cliente',
                    'executive_name' => 'Nombre del ejecutivo comercial',
                    'executive_phone' => 'Teléfono del ejecutivo (con link tel:)',
                    'executive_email' => 'Email del ejecutivo (con link mailto:)',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'purchase_order_status_update',
                'name' => 'Actualización de Orden de Compra',
                'subject' => 'Actualización de Orden - Finearom',
                'title' => 'Actualización de Orden',
                'header_content' => '<p>Estimado cliente,</p>
<p>Nos dirigimos a usted para mantenerlo informado sobre el estado actual de su orden de compra.</p>',
                'footer_content' => '|status_comment|

<div>
    <p><strong>Agradecemos su atención y confianza</strong></p>
</div>

<p>Si tiene alguna pregunta o requiere información adicional, no dude en contactarnos. Estamos aquí para brindarle el mejor servicio.</p>
<p>Cordialmente,</p>',
                'signature' => '<div>
    <p><strong>|sender_name|</strong></p>
    <p>Gestión de Órdenes</p>
    <p>Tel: +57 317 433 5096 | <a href="mailto:ordenes@finearom.com">ordenes@finearom.com</a> | <a href="https://finearom.com">www.finearom.com</a></p>
</div>',
                'available_variables' => [
                    'status_comment' => 'Comentario HTML sobre el estado de la orden',
                    'sender_name' => 'Nombre del remitente (default: EQUIPO FINEAROM)',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'client_autofill',
                'name' => 'Completar Información de Cliente',
                'subject' => 'Completa tu información - Finearom',
                'title' => 'Actualización de Información',
                'header_content' => '<p>Hola |client_name|,</p>
<p>Te enviamos el enlace para completar o actualizar tu información.</p>',
                'footer_content' => '<p>
    <a href="|link|">
        Completar información
    </a>
</p>
<p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
<p>|link|</p>
<p>Gracias.</p>',
                'signature' => '<div>
    <p><strong>EQUIPO FINEAROM</strong></p>
</div>',
                'available_variables' => [
                    'client_name' => 'Nombre del cliente',
                    'link' => 'URL del formulario de completar información',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'purchase_order_observation',
                'name' => 'Observaciones de Orden de Compra',
                'subject' => 'Observaciones Orden de Compra - Finearom',
                'title' => 'Observaciones de Orden de Compra',
                'header_content' => '<p><strong>Orden:</strong> |order_consecutive|</p>
<p><strong>Cliente:</strong> |client_name| (|client_nit|)</p>',
                'footer_content' => '|observations|

|internal_observations|',
                'signature' => '<div>
    <p><strong>Equipo Operaciones FINEAROM</strong></p>
    <p>Gestión de Órdenes</p>
    <p>Tel: +57 317 433 5096 | <a href="mailto:servicio.cliente@finearom.com">servicio.cliente@finearom.com</a> | <a href="https://finearom.com">www.finearom.com</a></p>
</div>',
                'available_variables' => [
                    'order_consecutive' => 'Consecutivo de la orden',
                    'client_name' => 'Nombre del cliente',
                    'client_nit' => 'NIT del cliente',
                    'observations' => 'HTML con las observaciones (incluye título si hay contenido)',
                    'internal_observations' => 'HTML con observaciones internas solo para planta (incluye título si hay contenido)',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'campaign',
                'name' => 'Campaña de Email Marketing',
                'subject' => 'Campaña - Finearom',
                'title' => 'Campaña Finearom',
                'header_content' => null,
                'footer_content' => '|body|

|tracking_pixel|',
                'signature' => '<div>
    <p><strong>EQUIPO FINEAROM</strong></p>
</div>',
                'available_variables' => [
                    'body' => 'Contenido HTML del cuerpo de la campaña',
                    'tracking_pixel' => 'Pixel de tracking (imagen 1x1 invisible)',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'client_autocreation',
                'name' => 'Auto-creación de Cliente',
                'subject' => 'Tu cuenta ha sido creada - Finearom',
                'title' => 'Cuenta Creada Exitosamente',
                'header_content' => '<p>Hola |client_name|,</p>
<p>Tu cuenta en FINEAROM ha sido creada exitosamente.</p>',
                'footer_content' => '<p>Pronto nos pondremos en contacto contigo para completar el proceso de bienvenida.</p>
<p>Gracias por tu interés en nuestros servicios.</p>',
                'signature' => '<div>
    <p><strong>EQUIPO FINEAROM</strong></p>
</div>',
                'available_variables' => [
                    'client_name' => 'Nombre del cliente',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'purchase_order_status_changed',
                'name' => 'Cambio de Estado de Orden',
                'subject' => 'Estado de Orden Actualizado - Finearom',
                'title' => 'Estado de Orden Actualizado',
                'header_content' => '<p>Estimado cliente,</p>
<p>Le informamos que el estado de su orden de compra ha sido actualizado.</p>',
                'footer_content' => '|order_details|

<p>Si tiene alguna consulta, no dude en contactarnos.</p>
<p>Cordialmente,</p>',
                'signature' => '<div>
    <p><strong>EQUIPO FINEAROM</strong></p>
    <p>Gestión de Órdenes</p>
    <p>Tel: +57 317 433 5096 | <a href="mailto:ordenes@finearom.com">ordenes@finearom.com</a> | <a href="https://finearom.com">www.finearom.com</a></p>
</div>',
                'available_variables' => [
                    'order_details' => 'Detalles HTML de la orden y el nuevo estado',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'purchase_order',
                'name' => 'Confirmación de Orden de Compra',
                'subject' => 'Re: |subject_client|',
                'title' => 'Orden de Compra',
                'header_content' => null,
                'footer_content' => '|template_content|',
                'signature' => '<div>
    <p><strong>EQUIPO FINEAROM</strong></p>
    <p>Atención al Cliente</p>
    <p>📱 +57 317 433 5096 | ✉️ <a href="mailto:info@finearom.com">info@finearom.com</a> | 🌐 <a href="https://finearom.com">www.finearom.com</a></p>
</div>',
                'available_variables' => [
                    'subject_client' => 'Subject del cliente (de la orden)',
                    'template_content' => 'Contenido HTML del template desde ConfigSystem',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'proforma',
                'name' => 'Envío de Proforma',
                'subject' => 'Proforma - Orden de Compra: |order_consecutive|',
                'title' => null,
                'header_content' => '<p>Estimado cliente,</p>
<p>Espero que se encuentren muy bien.</p>',
                'footer_content' => '<p>Adjuntamos la proforma correspondiente a su solicitud de pedido para su revisión y aprobación.</p>
<p>Cualquier inquietud adicional, estaremos atentos para brindarle el mejor servicio.</p>
<p>Que tengan un excelente día.</p>
<p>Cordialmente,</p>',
                'signature' => '<div>
    <p><strong>EQUIPO FINEAROM</strong></p>
    <p>Atención al Cliente</p>
    <p>📱 +57 317 433 5096 | ✉️ <a href="mailto:info@finearom.com">info@finearom.com</a> | 🌐 <a href="https://finearom.com">www.finearom.com</a></p>
</div>',
                'available_variables' => [
                    'order_consecutive' => 'Consecutivo de la orden',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'purchase_order_despacho',
                'name' => 'Procesamiento de Orden de Compra (Despacho)',
                'subject' => '|subject_client|',
                'title' => 'Procesamiento de Orden',
                'header_content' => '<p><strong>Buen día a todos,</strong></p>
<p>Espero que se encuentren muy bien.</p>
<p>Quisiera solicitar su ayuda con el procesamiento de la siguiente orden de compra:</p>

<div>
    <p><strong>📅 Fecha requerida de entrega:</strong></p>
    <p>|required_delivery_date|</p>
</div>

|order_comment|',
                'footer_content' => '|products_table|

<div>
    <h4>💱 Información TRM</h4>
    <div>|trm_info|</div>
</div>

<p><strong>Agradezco su atención y colaboración.</strong></p>
<p>Quedo muy atento a sus comentarios y confirmen por favor la disponibilidad para cumplir con la fecha requerida.</p>',
                'signature' => '<div>
    <p><strong>EQUIPO COMERCIAL FINEAROM</strong></p>
    <p>Gestión de Órdenes</p>
    <p>📱 +57 317 433 5096 | ✉️ <a href="mailto:comercial@finearom.com">comercial@finearom.com</a> | 🌐 <a href="https://finearom.com">www.finearom.com</a></p>
</div>',
                'available_variables' => [
                    'subject_client' => 'Subject del cliente (de la orden)',
                    'required_delivery_date' => 'Fecha requerida de entrega',
                    'order_comment' => 'HTML con observaciones importantes (si existen)',
                    'products_table' => 'Tabla HTML completa con detalle de productos',
                    'trm_info' => 'Información de TRM formateada',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'purchase_order_observation',
                'name' => 'Observaciones de Orden de Compra',
                'subject' => 'Observaciones - Orden: |order_consecutive|',
                'title' => 'Observaciones de Orden',
                'header_content' => '<p>Estimado cliente,</p>
<p>Nos dirigimos a usted para informarle sobre unas observaciones importantes relacionadas con su orden de compra.</p>',
                'footer_content' => '<div>
    <p><strong>Información de la Orden:</strong></p>
    <p><strong>Consecutivo:</strong> |order_consecutive|</p>
    <p><strong>Cliente:</strong> |client_name| (|client_nit|)</p>
</div>

<div style="margin: 20px 0;">
    <h3>📝 Observaciones</h3>
    <div>|observations|</div>
</div>

<p>Por favor, revise las observaciones anteriores y no dude en contactarnos si tiene alguna pregunta o inquietud.</p>
<p>Agradecemos su atención.</p>
<p>Cordialmente,</p>',
                'signature' => '<div>
    <p><strong>EQUIPO FINEAROM</strong></p>
    <p>Gestión de Órdenes</p>
    <p>📱 +57 317 433 5096 | ✉️ <a href="mailto:ordenes@finearom.com">ordenes@finearom.com</a> | 🌐 <a href="https://finearom.com">www.finearom.com</a></p>
</div>',
                'available_variables' => [
                    'order_consecutive' => 'Consecutivo de la orden',
                    'client_name' => 'Nombre del cliente',
                    'client_nit' => 'NIT del cliente',
                    'observations' => 'HTML con las observaciones',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'purchase_order_status_changed',
                'name' => 'Cambio de Estado de Orden',
                'subject' => 'Actualización de Estado - Orden: |order_consecutive|',
                'title' => 'Actualización de Orden',
                'header_content' => '<p>Estimado cliente,</p>
<p>Nos complace informarle que el estado de su orden de compra ha sido actualizado en nuestro sistema.</p>',
                'footer_content' => '<p><strong>Orden:</strong> |order_consecutive|</p>
<p><strong>Cliente:</strong> |client_name| (|client_nit|)</p>

<div style="margin: 20px 0;">
    <h3>Estado actualizado</h3>
    <p><strong>Nuevo estado:</strong> |status_label|</p>
</div>

<div style="margin: 20px 0;">
    <h3>Detalles de la Orden</h3>
    <p><strong>Fecha de Creación:</strong> |order_creation_date|</p>
    <p><strong>Fecha de Entrega Requerida:</strong> |required_delivery_date|</p>
    <p><strong>Dirección de Entrega:</strong> |delivery_address|</p>
</div>

<p>¡Gracias por confiar en Finearom!</p>
<p>Continuamos trabajando para brindarle el mejor servicio y mantenerlo informado sobre el progreso de su orden.</p>',
                'signature' => '<div>
    <p><strong>EQUIPO FINEAROM</strong></p>
    <p>Gestión de Órdenes</p>
    <p>Tel: +57 317 433 5096 | <a href="mailto:ordenes@finearom.com">ordenes@finearom.com</a> | <a href="https://finearom.com">www.finearom.com</a></p>
</div>',
                'available_variables' => [
                    'order_consecutive' => 'Consecutivo de la orden',
                    'client_name' => 'Nombre del cliente',
                    'client_nit' => 'NIT del cliente',
                    'status_label' => 'Estado traducido (Pendiente, En Proceso, etc.)',
                    'order_creation_date' => 'Fecha de creación de la orden',
                    'required_delivery_date' => 'Fecha de entrega requerida',
                    'delivery_address' => 'Dirección de entrega',
                ],
                'is_active' => true,
            ],
        ];

        foreach ($templates as $template) {
            EmailTemplate::updateOrCreate(
                ['key' => $template['key']],
                $template
            );
        }
    }
}
