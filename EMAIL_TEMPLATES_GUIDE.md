# Guía de Uso del Sistema de Email Templates

Este sistema permite configurar y editar plantillas de email desde la interfaz web, usando variables dinámicas con la sintaxis `|variable|`.

## Tabla de Contenidos
1. [Uso con Laravel Mailables](#uso-con-laravel-mailables)
2. [Uso con Symfony Mailer (DSN Personalizado)](#uso-con-symfony-mailer-dsn-personalizado)
3. [Variables Disponibles](#variables-disponibles)
4. [Layouts Disponibles](#layouts-disponibles)

---

## Uso con Laravel Mailables

### Método 1: Usar TemplateMail (Recomendado para nuevos emails)

```php
use App\Mail\TemplateMail;
use Illuminate\Support\Facades\Mail;

// Enviar email usando un template
Mail::to('cliente@ejemplo.com')
    ->send(new TemplateMail(
        'client_welcome', // Key del template
        [
            'client_name' => $client->client_name,
            'executive_name' => $executive->name,
            'executive_phone' => '<a href="tel:' . $executive->phone . '">' . $executive->phone . '</a>',
            'executive_email' => '<a href="mailto:' . $executive->email . '">' . $executive->email . '</a>',
            'base_url' => url(''),
        ],
        null, // Subject personalizado (opcional, usa el del template si es null)
        'template_centered' // Layout (opcional, default: 'template')
    ));
```

### Método 2: Actualizar un Mailable existente

**Antes:**
```php
<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class ClientAutofillMail extends Mailable
{
    public $client;
    public $link;

    public function build()
    {
        return $this->subject('Completa tu información')
            ->view('emails.client_autofill');
    }
}
```

**Después:**
```php
<?php

namespace App\Mail;

use App\Services\EmailTemplateService;
use Illuminate\Mail\Mailable;

class ClientAutofillMail extends Mailable
{
    public $client;
    public $link;

    public function build()
    {
        $service = new EmailTemplateService();
        $variables = [
            'client_name' => $this->client->client_name,
            'link' => $this->link,
        ];

        $rendered = $service->renderTemplate('client_autofill', $variables);
        $subject = $service->getRenderedSubject('client_autofill', $variables);

        return $this->subject($subject)
            ->view('emails.template', $rendered);
    }
}
```

---

## Uso con Symfony Mailer (DSN Personalizado)

Cuando necesitas usar un DSN personalizado (como en observaciones de órdenes), usa el método `getRenderedHtml()`:

### Ejemplo: Actualizar observaciones de Purchase Order

**Antes:**
```php
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

// Configurar mailer
$dsn = $this->resolveMailerDsn($userEmail);
$transport = Transport::fromDsn($dsn);
$mailer = new Mailer($transport);

// Renderizar vista tradicional
$clientBody = view('emails.purchase_order_observation', [
    'order' => $order,
    'observationHtml' => $tablesOnly,
    'forClient' => true,
])->render();

// Enviar email
$clientMail = (new Email())
    ->from($userEmail)
    ->to($clientTo)
    ->subject($subject)
    ->html($clientBody);

$mailer->send($clientMail);
```

**Después:**
```php
use App\Services\EmailTemplateService;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

// Configurar mailer
$dsn = $this->resolveMailerDsn($userEmail);
$transport = Transport::fromDsn($dsn);
$mailer = new Mailer($transport);

// Usar EmailTemplateService
$templateService = new EmailTemplateService();

// Preparar variables (las observaciones se construyen como HTML)
$observationsHtml = '';
if (!empty($tablesOnly)) {
    $observationsHtml = '<div><h3>Observaciones</h3><div>' . $tablesOnly . '</div></div>';
}

$variables = [
    'order_consecutive' => $order->order_consecutive,
    'client_name' => $order->client->client_name,
    'client_nit' => $order->client->nit,
    'observations' => $observationsHtml,
    'internal_observations' => '', // Vacío para cliente
];

// Renderizar template
$clientBody = $templateService->getRenderedHtml('purchase_order_observation', $variables, 'template');
$subject = $templateService->getRenderedSubject('purchase_order_observation', $variables);

// Enviar email
$clientMail = (new Email())
    ->from($userEmail)
    ->to($clientTo)
    ->subject($subject)
    ->html($clientBody);

$mailer->send($clientMail);
```

---

## Variables Disponibles

### Template: `client_welcome`
- `|client_name|` - Nombre del cliente
- `|executive_name|` - Nombre del ejecutivo comercial
- `|executive_phone|` - Teléfono del ejecutivo (incluir HTML `<a href="tel:...">`)
- `|executive_email|` - Email del ejecutivo (incluir HTML `<a href="mailto:...">`)
- `|base_url|` - URL base de la aplicación

### Template: `client_autofill`
- `|client_name|` - Nombre del cliente
- `|link|` - URL del formulario de autofill

### Template: `purchase_order_observation`
- `|order_consecutive|` - Consecutivo de la orden
- `|client_name|` - Nombre del cliente
- `|client_nit|` - NIT del cliente
- `|observations|` - HTML con las observaciones (incluye título si hay contenido)
- `|internal_observations|` - HTML con observaciones internas (solo para emails internos)

### Template: `purchase_order_status_update`
- `|status_comment|` - Comentario HTML sobre el estado
- `|sender_name|` - Nombre del remitente (default: EQUIPO FINEAROM)

### Template: `campaign`
- `|body|` - Contenido HTML del cuerpo de la campaña
- `|tracking_pixel|` - Pixel de tracking (imagen 1x1 invisible)

---

## Layouts Disponibles

### `template` (Layout estándar)
- Logo alineado a la izquierda
- Título con estilo especial (si se proporciona)
- Header content
- Footer content
- Signature
- Copyright automático

### `template_centered` (Layout con logo centrado)
- Logo centrado
- Título centrado con estilo especial (si se proporciona)
- Header content
- Footer content
- Signature
- Sin border-top en footer

---

## Construir Variables con HTML

Cuando necesites pasar contenido HTML dinámico, constrúyelo antes de pasarlo:

```php
// Ejemplo: Construir observaciones con título condicional
$observationsHtml = '';
if (!empty($observation)) {
    $observationsHtml = '<div><h3>Observaciones</h3><div>' . $observation . '</div></div>';
}

// Ejemplo: Construir observaciones internas
$internalObservationsHtml = '';
if (!empty($internalObservation) && !$forClient) {
    $internalObservationsHtml = '<div style="margin-top: 20px;">';
    $internalObservationsHtml .= '<h3>Observaciones internas (solo planta)</h3>';
    $internalObservationsHtml .= '<div>' . $internalObservation . '</div>';
    $internalObservationsHtml .= '</div>';
}

$variables = [
    'observations' => $observationsHtml,
    'internal_observations' => $internalObservationsHtml,
];
```

---

## Editar Templates desde la Interfaz

1. Ir a **Configuración > Plantillas Email**
2. Hacer clic en **Editar** en la plantilla deseada
3. Modificar:
   - **Asunto**: Subject del email
   - **Título**: Título con estilo especial (opcional)
   - **Header Content**: Contenido inicial del email
   - **Footer Content**: Contenido final (aquí van las variables dinámicas como `|observations|`)
   - **Firma**: Firma del email (opcional)
4. Ver las **Variables Disponibles** en la sección azul
5. Guardar cambios

Los cambios se aplican inmediatamente sin necesidad de deployar código.

---

## Notas Importantes

1. **Las variables usan pipes `|variable|`** para evitar conflictos con Blade `{{ }}`
2. **El título es un campo separado** con estilo especial automático
3. **Las variables pueden contener HTML** - úsalo para contenido dinámico complejo
4. **Los templates están en la base de datos** - se pueden editar sin tocar código
5. **Usa `available_variables`** en el seeder para documentar qué variables acepta cada template
