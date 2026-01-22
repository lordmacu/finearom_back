<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            max-width: 960px;
            width: 100%;
            box-sizing: border-box;
            font-size: 13px;
        }
        .content-wrapper {
            max-width: 900px;
            margin: 0 auto;
        }
        header {
            text-align: left;
            margin-bottom: 20px;
        }
        .logo {
            width: 100px;
            height: auto;
        }
        .email-title-container {
            margin-bottom: 60px;
            padding: 0;
            display: block;
        }
        .email-title {
            display: inline-block;
            font-weight: bold;
            font-size: 36px;
            margin: 0;
            padding: 0 10px 0 0;
            background: linear-gradient(to top, #ebebeb 40%, transparent 40%);
            border-radius: 0 5px 5px 0;
            color: #1F2345;
            line-height: 1.2;
        }
        table {
            width: 100%;
            max-width: 900px;
            border-collapse: collapse;
            margin: 20px 0;
            border: 1px solid #ddd;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
        }
        th {
            font-weight: bold;
            background-color: #f4f4f4;
        }
        footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #d6d8d8;
            font-size: 14px;
        }
        a {
            color: #1F2345 !important;
            text-decoration: underline;
        }
        /* Estilos específicos de cartera para colores */
        strong {
            color: #1F2345;
        }
    </style>
</head>
<body>
    <header>
        <img class="logo" src="https://ordenes.finearom.co/images/logo.png" alt="Finearom Logo">
    </header>

    <main class="content-wrapper">
        @if(!empty($title))
            <div class="email-title-container">
                <h1 class="email-title">{{ $title }}</h1>
            </div>
        @endif

        @if(!empty($header_content))
            <div>
                {!! $header_content !!}
            </div>
        @endif

        @if(!empty($footer_content))
            <div>
                {!! $footer_content !!}
            </div>
        @endif
    </main>

    <footer>
        @if(!empty($signature))
            <div>
                {!! $signature !!}
            </div>
        @endif
        <p><small>Este es un mensaje automático. Por favor, no responda a este correo.</small></p>
        <p><small>&copy; {{ date('Y') }} Finearom. Todos los derechos reservados.</small></p>
    </footer>
</body>
</html>
