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
            <img src="|base_url|/images/gestioncartera.jpg" alt="Gestión de Cartera" width="170" height="90" style="display:block; margin:0 auto;">
        </td>
        <td style="padding:10px; border:0;">
            <img src="|base_url|/images/comercial.jpg" alt="Ejecutiva Comercial" width="170" height="90" style="display:block; margin:0 auto;">
        </td>
        <td style="padding:10px; border:0;">
            <img src="|base_url|/images/logistica.jpg" alt="Logística y Despachos" width="170" height="90" style="display:block; margin:0 auto;">
        </td>
    </tr>
    <tr>
        <td style="padding:10px; border:0; vertical-align:top;">
            <p style="margin:8px 0 0 0; text-align:center;">
                <a href="mailto:facturacion@finearom.com">facturacion@finearom.com</a>
            </p>
        </td>
        <td style="padding:10px; border:0; vertical-align:top;">
            <p style="margin:8px 0 0 0; text-align:center;">
                <strong>Ejecutiva Comercial</strong><br>
                |executive_name|<br>
                |executive_phone|<br>
                |executive_email|
            </p>
        </td>
        <td style="padding:10px; border:0; vertical-align:top;">
            <p style="margin:8px 0 0 0; text-align:center;">
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
<p>Para finalizar tu autocreacion, completa el formulario en el siguiente enlace:</p>
<p><a href="|link|">Completar formulario de autocreacion</a></p>
<p>Si el boton no funciona, copia y pega este enlace:</p>
<p>|link|</p>
<p>Gracias por tu interes en nuestros servicios.</p>',
                'signature' => '<div>
    <p><strong>EQUIPO FINEAROM</strong></p>
</div>',
                'available_variables' => [
                    'client_name' => 'Nombre del cliente',
                    'link' => 'URL del formulario de autocreacion',
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
                    'branch_offices' => 'Listado de sucursales del cliente (nombre, NIT, direccion de entrega y ciudad)',
                    'client_name' => 'Nombre del cliente',
                    'client_nit' => 'NIT del cliente',
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
            [
                'key' => 'portfolio_block_alert',
                'name' => 'Alerta de Bloqueo de Despacho',
                'subject' => 'Finearom - Alerta de bloqueo: |client_name|',
                'title' => 'Alerta de Bloqueo de Despacho',
                'header_content' => '<p>Estimada |ejecutiva|,</p>
<p>El cliente <strong>|client_name|</strong> tiene una alerta de bloqueo de despachos, ya que una o más facturas llevan <strong>5 (cinco) o más días vencidas</strong>, a saber:</p>',
                'footer_content' => '|invoices_table|

<p style="font-size: 12px; margin-top: 10px;"><em>Leyenda: F = Factura | R = Recaudo | Días por vencer en <strong>negro</strong> y vencidos en <span style="color: red;">rojo</span></em></p>

|balance_info|

<p>Por lo anterior, se requiere su colaboración en la gestión del pago de las facturas en referencia para la consecuente liberación de las órdenes de compra relacionadas a continuación:</p>

|blocked_orders_table|

<p>Para cancelar los saldos pendientes puede hacer consignación/transferencia electrónica a nuestra cuenta bancaria:</p>
<p><strong>Banco Bancolombia - Cuenta corriente 122-792013-00</strong></p>

<p><strong>Nota:</strong> Si ya se realizó el pago correspondiente, por favor compartir el comprobante de pago y el movimiento contable en MS Excel para su registro.</p>

<p>En caso de dudas o inconsistencias, por favor comuníquese con nuestra colega <strong style="color: #0070c0;">Jazmín Rincón</strong> de nuestro departamento de cartera al correo electrónico <a href="mailto:cartera@finearom.com">cartera@finearom.com</a> o a su número celular/WhatsApp <a href="https://wa.me/573195700729">+57 318 302 5170</a>.</p>

<p>Sin otro asunto en particular, agradecemos su gentil atención y quedamos atentos a cualquier duda o inquietud.</p>
<p><strong>Atentamente,</strong></p>',
                'signature' => '<div>
    <p><strong>Finearom S.A.S.</strong></p>
    <p><strong>Sede Administrativa:</strong> <a href="https://www.google.com/maps?q=Carrera+50+N%C2%B0+134D+%E2%80%93+31+%7C+Bogot%C3%A1,+Colombia&entry=gmail&source=g">Carrera 50 # 134D - 31 | Prado Veraniego, Bogotá, Colombia</a></p>
    <p><strong>Planta:</strong> <a href="https://maps.app.goo.gl/XbCw3qQi3A8ZXJMP8">Calle 131 # 50 – 35 | Prado Veraniego, Bogotá, Colombia.</a></p>
    <p><a href="mailto:cartera@finearom.com">servicio.cliente@finearom.com</a> - <strong>Teléfono</strong>: +57 316 401 2217</p>
</div>',
                'available_variables' => [
                    'client_name' => 'Nombre del cliente',
                    'ejecutiva' => 'Nombre de la ejecutiva comercial',
                    'invoices_table' => 'Tabla HTML con facturas vencidas',
                    'balance_info' => 'HTML con información de saldos (por vencer y vencidos)',
                    'blocked_orders_table' => 'Tabla HTML con órdenes bloqueadas',
                ],
                'is_active' => true,
            ],
            [
                'key' => 'portfolio_status',
                'name' => 'Estado de Cartera',
                'subject' => 'Finearom - Estado de cartera: |client_name|',
                'title' => 'Estado de cartera',
                'header_content' => '<p style="text-align: right; margin-bottom: 30px;"><strong>Señores:</strong> |client_name|</p>
<p>Estimado cliente,</p>
<p>Esperamos se encuentren muy bien.</p>
<p>Compartimos a continuación el estado de cartera a la fecha respecto de las facturas vencidas y/o próximas a vencer. Esto con el objetivo de confirmar, solicitar o acordar su fecha de pago.</p>',
                'footer_content' => '|balance_info|

<p>Encuentre a continuación el detalle de las facturas y sus saldos correspondientes:</p>

|invoices_table|

<p style="font-size: 12px; margin-top: 10px;"><em>Leyenda: F = Factura | R = Recaudo | Días por vencer en <strong>negro</strong> y vencidos en <span style="color: red;">rojo</span></em></p>

<p>Para cancelar los saldos pendientes puede hacer consignación/transferencia electrónica a nuestra cuenta bancaria:</p>
<p><strong>Banco Bancolombia - Cuenta corriente 122-792013-00</strong></p>

<p>Si algún saldo no coincide; por favor enviar los movimientos contables del año |previous_year| y |current_year|, en Excel, donde se especifiquen Factura, valor cancelado y fecha de pago.</p>

<p>En caso de dudas o inconsistencias, por favor comuníquese con nuestra colega <strong style="color: #0070c0;">Jazmín Rincón</strong> de nuestro departamento de cartera al correo electrónico <a href="mailto:cartera@finearom.com">cartera@finearom.com</a> o a su número celular/WhatsApp <a href="https://wa.me/573195700729">+57 318 302 5170</a>.</p>

<p>Sin otro asunto en particular, agradecemos su gentil atención y quedamos atentos a cualquier duda o inquietud.</p>
<p><strong>Atentamente,</strong></p>',
                'signature' => '<div>
    <p><strong>Finearom S.A.S.</strong></p>
    <p><strong>Sede Administrativa:</strong> <a href="https://www.google.com/maps?q=Carrera+50+N%C2%B0+134D+%E2%80%93+31+%7C+Bogot%C3%A1,+Colombia&entry=gmail&source=g">Carrera 50 # 134D - 31 | Prado Veraniego, Bogotá, Colombia</a></p>
    <p><strong>Planta:</strong> <a href="https://maps.app.goo.gl/XbCw3qQi3A8ZXJMP8">Calle 131 # 50 – 35 | Prado Veraniego, Bogotá, Colombia.</a></p>
    <p><a href="mailto:cartera@finearom.com">servicio.cliente@finearom.com</a> - <strong>Teléfono</strong>: +57 316 401 2217</p>
</div>',
                'available_variables' => [
                    'client_name' => 'Nombre del cliente',
                    'balance_info' => 'HTML con información de saldos (por vencer y vencidos)',
                    'invoices_table' => 'Tabla HTML con facturas y saldos',
                    'previous_year' => 'Año anterior',
                    'current_year' => 'Año actual',
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
