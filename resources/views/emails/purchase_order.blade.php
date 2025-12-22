<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email - Finearom</title>
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

        .content {
            margin-bottom: 30px;
            text-align: justify;
        }

        .content h1,
        .content h2,
        .content h3 {
            color: #2c5aa0;
            margin-top: 25px;
            margin-bottom: 15px;
        }

        .content h1 {
            font-size: 22px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 10px;
        }

        .content h2 {
            font-size: 20px;
        }

        .content h3 {
            font-size: 18px;
        }

        .content p {
            margin-bottom: 15px;
        }

        .content ul,
        .content ol {
            margin-bottom: 15px;
            padding-left: 25px;
        }

        .content li {
            margin-bottom: 8px;
        }

        .content a {
            color: #2c5aa0;
            text-decoration: none;
        }

        .content a:hover {
            text-decoration: underline;
        }

        .content blockquote {
            background: #f8f9fa;
            padding: 15px;
            border-left: 4px solid #2c5aa0;
            margin: 20px 0;
            font-style: italic;
        }

        .content table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }

        .content table th,
        .content table td {
            padding: 12px;
            border: 1px solid #dee2e6;
            text-align: left;
        }

        .content table th {
            background: #2c5aa0;
            color: white;
            font-weight: bold;
        }

        .content table tr:nth-child(even) {
            background: #f8f9fa;
        }

        .highlight-box {
            background: linear-gradient(135deg, #2c5aa0, #1e3f73);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
            text-align: center;
        }

        .info-box {
            background: #e8f4fd;
            border: 1px solid #2c5aa0;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }

        .success-box {
            background: #d4edda;
            border: 1px solid #28a745;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #2c5aa0, #1e3f73);
            color: #ffffff !important;
            padding: 15px 30px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 16px;
            box-shadow: 0 4px 15px rgba(44, 90, 160, 0.3);
            transition: all 0.3s ease;
            margin: 10px 0;
        }

        .cta-button:hover {
            background: linear-gradient(135deg, #1e3f73, #2c5aa0);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(44, 90, 160, 0.4);
            color: #ffffff !important;
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

            .content table {
                font-size: 12px;
            }

            .content table th,
            .content table td {
                padding: 8px;
            }

            .cta-button {
                padding: 12px 24px;
                font-size: 14px;
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
        </div>

        <div class="content">
            {!! $templateContent !!}
        </div>

        <div class="signature">
            <div class="signature-name">EQUIPO FINEAROM</div>
            <div class="signature-title">Atención al Cliente</div>
            <div class="contact-info">
                📱 <a href="tel:+573174335096">+57 317 433 5096</a><br>
                ✉️ <a href="mailto:info@finearom.com">info@finearom.com</a><br>
                🌐 <a href="https://finearom.com">www.finearom.com</a>
            </div>
        </div>

        <div class="footer">
            <p>Este es un mensaje automático. Por favor, no responda a este correo.</p>
            <p>&copy; {{ date('Y') }} Finearom. Todos los derechos reservados.</p>
        </div>
    </div>
</body>

</html>
