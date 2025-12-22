<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Completa tu información</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111; line-height: 1.6;">
    <h2>Hola {{ $client->client_name ?? 'Cliente' }},</h2>
    <p>Te enviamos el enlace para completar o actualizar tu información.</p>
    <p>
        <a href="{{ $link }}" style="display:inline-block;padding:10px 16px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">
            Completar información
        </a>
    </p>
    <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
    <p style="word-break: break-all;">{{ $link }}</p>
    <p>Gracias.</p>
</body>
</html>
