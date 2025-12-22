@php
    $statusComment = $purchaseOrder->comments->where('type', 'new_status_comment')->last();
@endphp
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Actualización de Orden de Compra</title>
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

        .logo {
            margin-right: 20px;
        }

        .logo img {
            max-width: 150px;
            height: auto;
        }

        .header-text {
            flex: 1;
        }

        .header-text h1 {
            color: #2c5aa0;
            font-size: 24px;
            margin: 0;
            font-weight: bold;
        }

        .header-text .subtitle {
            color: #666;
            font-size: 14px;
            margin: 5px 0 0 0;
        }

        .greeting {
            font-size: 18px;
            color: #2c5aa0;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .content {
            margin-bottom: 30px;
            text-align: justify;
        }

        .observations-section {
            background: #e8f4fd;
            border: 2px solid #2c5aa0;
            padding: 20px;
            border-radius: 10px;
            margin: 25px 0;
            border-left: 6px solid #2c5aa0;
        }

        .observations-title {
            color: #2c5aa0;
            font-size: 18px;
            font-weight: bold;
            margin: 0 0 15px 0;
            display: flex;
            align-items: center;
        }

        .observations-content {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            font-size: 16px;
            line-height: 1.5;
        }

        .appreciation {
            background: linear-gradient(135deg, #2c5aa0, #1e3f73);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin: 25px 0;
        }

        .appreciation p {
            margin: 0;
            font-size: 16px;
            font-weight: 500;
        }

        .closing {
            margin: 30px 0 20px 0;
            font-size: 16px;
        }

        .signature {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }

        .signature-formal {
            margin-bottom: 20px;
            font-size: 16px;
            color: #666;
        }

        .signature-name {
            font-weight: bold;
            font-size: 18px;
            color: #2c5aa0;
            margin-bottom: 15px;
        }

        .signature-details {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #2c5aa0;
        }

        .signature-title {
            font-weight: 500;
            color: #666;
            margin-bottom: 10px;
        }

        .contact-info {
            font-size: 14px;
            color: #666;
        }

        .contact-info a {
            color: #2c5aa0;
            text-decoration: none;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            text-align: center;
            font-size: 12px;
            color: #999;
        }

        .icon {
            margin-right: 8px;
            font-size: 20px;
        }

        @media (max-width: 600px) {
            body {
                padding: 10px;
            }

            .email-container {
                padding: 20px;
            }

            .header {
                flex-direction: column;
                text-align: center;
            }

            .logo {
                margin-right: 0;
                margin-bottom: 15px;
            }

            .logo img {
                max-width: 60px;
            }

            .observations-title {
                font-size: 16px;
            }

            .signature-name {
                font-size: 16px;
            }
        }
    </style>
</head>

<body>
    <div class="email-container">
        <div class="header">
            <div class="logo">
                <img width="150px" src="https://ordenes.finearom.co/images/logo.png" alt="Finearom Logo">
            </div>
            <div class="header-text">
                <h1>Actualización de Orden</h1>
                <div class="subtitle">Comunicación Importante</div>
            </div>
        </div>

        <div class="greeting">
            Estimado cliente,
        </div>

        <div class="content">
            <p>Nos dirigimos a usted para mantenerlo informado sobre el estado actual de su orden de compra.</p>
        </div>

        @if ($statusComment)
            <div class="observations-section">
                <div class="observations-title">
                    <span class="icon">📋</span>Observaciones Importantes
                </div>
                <div class="observations-content">
                    {!! $statusComment->text !!}
                </div>
            </div>
        @endif

        <div class="appreciation">
            <p><strong>Agradecemos su atención y confianza</strong></p>
        </div>

        <div class="content">
            <p>Si tiene alguna pregunta o requiere información adicional, no dude en contactarnos. Estamos aquí para
                brindarle el mejor servicio.</p>
        </div>

        <div class="closing">
            <p><strong>Cordialmente,</strong></p>
        </div>

        <div class="signature">
            <div class="signature-name">
                {{ $purchaseOrder->sender_name ?? 'EQUIPO FINEAROM' }}
            </div>

            <div class="signature-details">
                <div class="signature-title">Gestión de Órdenes</div>
                <div class="contact-info">
                    📱 <a href="tel:+573174335096">+57 317 433 5096</a><br>
                    ✉️ <a href="mailto:ordenes@finearom.com">ordenes@finearom.com</a><br>
                    🌐 <a href="https://finearom.com">www.finearom.com</a>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>Este es un mensaje automático. Por favor, no responda a este correo.</p>
            <p>&copy; {{ date('Y') }} Finearom. Todos los derechos reservados.</p>
        </div>
    </div>
</body>

</html>
