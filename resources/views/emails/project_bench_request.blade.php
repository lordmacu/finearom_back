<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $asunto }}</title>
</head>
<body style="font-family: Arial, sans-serif; color:#333; line-height:1.6; font-size:14px; max-width:720px;">
    <p>Buen día, requerimos tu amable ayuda con la compra del bench relacionado a continuación</p>

    <table style="width:100%; border-collapse:collapse; margin:16px 0;">
        <tbody>
            <tr>
                <td style="border:1px solid #ddd; padding:8px 12px; background:#f8f8f8; font-weight:bold; color:#1F2345; white-space:nowrap;">Proyecto</td>
                <td style="border:1px solid #ddd; padding:8px 12px;">{{ $project->nombre }}</td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:8px 12px; background:#f8f8f8; font-weight:bold; color:#1F2345; white-space:nowrap;">Cliente</td>
                <td style="border:1px solid #ddd; padding:8px 12px;">{{ $project->client?->client_name ?? $project->nombre_prospecto ?? '—' }}</td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:8px 12px; background:#f8f8f8; font-weight:bold; color:#1F2345; white-space:nowrap;">Ejecutiva</td>
                <td style="border:1px solid #ddd; padding:8px 12px;">{{ $project->ejecutivo ?? '—' }}</td>
            </tr>
            <tr>
                <td style="border:1px solid #ddd; padding:8px 12px; background:#f8f8f8; font-weight:bold; color:#1F2345; white-space:nowrap; vertical-align:top;">Descripción del bench</td>
                <td style="border:1px solid #ddd; padding:8px 12px; white-space:pre-line;">{{ $evaluation->bench_text }}</td>
            </tr>
        </tbody>
    </table>

    <p>La foto del bench va adjunta a este correo.</p>
    <p><a href="{{ $url }}">Ver el proyecto</a></p>
</body>
</html>
