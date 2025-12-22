<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>¡Bienvenido a FINEAROM!</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f4f4f4;
        }

        .email-container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
        }

        .logo img {
            max-width: 150px;
            height: auto;
        }

        .greeting {
            font-size: 18px;
            color: #2c5aa0;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .welcome-title {
            font-size: 22px;
            color: #2c5aa0;
            margin-bottom: 25px;
            font-weight: bold;
            text-align: center;
        }

        .content {
            margin-bottom: 30px;
            text-align: justify;
        }

        .contact-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin: 30px 0;
            border-left: 4px solid #2c5aa0;
        }

        .contact-section h3 {
            color: #2c5aa0;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 16px;
            font-weight: bold;
        }

        .contact-item {
            margin-bottom: 20px;
            padding: 15px;
            background: white;
            border-radius: 6px;
            border: 1px solid #e9ecef;
        }

        .contact-item:last-child {
            margin-bottom: 0;
        }

        .contact-title {
            font-weight: bold;
            color: #2c5aa0;
            margin-bottom: 8px;
        }

        .contact-details {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        .contact-details a {
            color: #2c5aa0;
            text-decoration: none;
        }

        .signature {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }

        .signature-name {
            font-weight: bold;
            font-size: 16px;
            color: #2c5aa0;
            margin-bottom: 5px;
        }

        .signature-title {
            font-weight: 500;
            color: #666;
            margin-bottom: 10px;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            text-align: center;
            font-size: 12px;
            color: #999;
        }

        .highlight {
            font-weight: bold;
            color: #2c5aa0;
        }
    </style>
</head>

<body>
    <div class="email-container">
        <div class="header">
            <div class="logo">
                <img src="https://ordenes.finearom.co/images/logo.png" alt="Finearom Logo">
            </div>
        </div>

        <div class="greeting">
            Estimado/a {{ $client->client_name }}:
        </div>

        <div class="welcome-title">
            ¡Es un honor darte la bienvenida a FINEAROM!
        </div>

        <div class="content">
            <p>Estamos encantados de convertirnos en un <span class="highlight">aliado estratégico</span> en la
                perfumación exitosa de sus nuevos productos. Nuestro compromiso con la calidad, la innovación exclusiva
                y el servicio personalizado será siempre nuestro principal y único objetivo, el de agregar valor a sus
                marcas.</p>

            <p>Nos mueve el propósito de ser un <span class="highlight">proveedor integral</span>. Nuestras propuestas
                están basadas en una exploración constante del mercado, sus tendencias, la visualización y concreción de
                oportunidades dentro de su consumidor objetivo.</p>
        </div>

        <div class="contact-section">
            <h3>Para garantizar una comunicación efectiva y fluida, te compartimos los datos de contacto:</h3>

            <div class="contact-item">
                <div class="contact-title">Ejecutiva Comercial:</div>
                <div class="contact-details">
                    <div>{{ $executiveName ?? 'Equipo Comercial' }}</div>
                    @if ($executiveEmail)
                        ✉️ <a href="mailto:{{ $executiveEmail }}">{{ $executiveEmail }}</a><br>
                    @endif
                    @if ($executivePhone)
                        📱 <a href="tel:{{ $executivePhone }}">{{ $executivePhone }}</a>
                    @endif
                </div>
            </div>

            <div class="contact-item">
                <div class="contact-title">Gestión de Cartera:</div>
                <div class="contact-details">
                    ✉️ <a href="mailto:facturacion@finearom.com">facturacion@finearom.com</a>
                </div>
            </div>

            <div class="contact-item">
                <div class="contact-title">Logística y Despachos:</div>
                <div class="contact-details">
                    ✉️ <a href="mailto:analista.operaciones@finearom.com">analista.operaciones@finearom.com</a>
                </div>
            </div>
        </div>

        <div class="content">
            <p>Si tienes alguna duda, no dudes en contactarnos. <span class="highlight">¡Estamos aquí para ayudarte!</span></p>
            <p style="text-align: center; font-weight: bold; color: #2c5aa0; font-size: 18px;">Gracias por confiar en FINEAROM</p>
        </div>

        <div class="signature">
            <div class="signature-name">MÓNICA CASTAÑO</div>
            <div class="signature-title">Dirección Comercial</div>
            <div class="contact-info" style="font-size: 14px; color: #666;">
                📱 +57 317 433 5096<br>
                ✉️ monica.castano@finearom.com
            </div>
        </div>

        <div class="footer">
            <p>Este es un mensaje automático. Por favor, no responda a este correo.</p>
            <p>&copy; {{ date('Y') }} Finearom. Todos los derechos reservados.</p>
        </div>
    </div>
</body>

</html>
