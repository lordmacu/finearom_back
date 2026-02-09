<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Email - Finearom')</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #121e45 !important;
            line-height: 1.6;
            margin: 0;
            padding: 20px;
            max-width: 960px;
            width: 100%;
            box-sizing: border-box;
        }
        .content-wrapper {
            max-width: 900px;
            margin: 0 auto;
        }
        p, li, td, th, div, span, small, strong, h1, h2, h3, h4, h5, h6 {
            color: #121e45 !important;
        }
        header {
            text-align: left;
            margin-bottom: 20px;
        }
        .logo {
            width: 100px;
            height: auto;
        }
        p, li, td, th, div, span, small, strong{
            font-size: 14px;
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
            padding: 0 10px 0 0; /* Un poco menos de espacio a la derecha */
            background: linear-gradient(to top, #ebebeb 40%, transparent 40%);
            border-radius: 0 5px 5px 0;
            line-height: 1.2;
        }
        table {
            width: 100%;
            max-width: 900px;
            border-collapse: collapse;
            margin: 20px 0;
            border: 1px solid #121e45;
        }
        th, td {
            border: 1px solid #121e45;
            padding: 14px 16px;
            text-align: left;
            color: #121e45 !important;
        }
        th {
            font-weight: bold;
            background-color: #f8f9fa;
        }
        footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #d6d8d8;
            font-size: 14px;
        }
        a {
            color: #121e45 !important;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <header>
        <img class="logo" src="https://ordenes.finearom.co/images/logo.png" alt="Finearom Logo">
    </header>

    <main class="content-wrapper">
        @hasSection('email_title')
            <div class="email-title-container">
                <h1 class="email-title">@yield('email_title')</h1>
            </div>
        @endif

        @yield('content')
    </main>

    <footer>
        @yield('signature')
        <p><small>&copy; {{ date('Y') }} Finearom. Todos los derechos reservados.</small></p>
    </footer>
</body>
</html>
