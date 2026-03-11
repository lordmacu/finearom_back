<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <!--[if mso]>
    <noscript>
        <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
    </noscript>
    <![endif]-->
    <style>
        p, li, td, th, div, span, small, strong, h1, h2, h3, h4, h5, h6 {
            color: #1F2345;
        }
        p, li, span, small, strong {
            font-size: 13px;
        }
        table {
            width: 100%;
            max-width: 700px;
            border-collapse: collapse;
            margin: 16px 0;
            border: 1px solid #1F2345;
        }
        th, td {
            border: 1px solid #1F2345;
            padding: 10px 14px;
            text-align: left;
            color: #1F2345;
        }
        th {
            font-weight: bold;
            background-color: #f8f9fa;
        }
        a {
            color: #1F2345;
            text-decoration: underline;
        }
        h4 {
            margin: 12px 0 6px 0;
            font-size: 14px;
            color: #1F2345;
        }
    </style>
</head>
<body style="font-family:Arial,sans-serif;color:#1F2345;line-height:1.6;margin:0;padding:20px;font-size:13px;background-color:#ffffff;">

    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:700px;margin:0 auto;">
        <tr>
            <td style="padding-bottom:20px;border-bottom:2px solid #1F2345;">
                <img src="https://ordenes.finearom.co/images/logo.png"
                     alt="Finearom Logo"
                     width="100"
                     height="auto"
                     style="width:100px;height:auto;display:block;border:0;">
            </td>
        </tr>

        @if(!empty($title))
        <tr>
            <td style="padding:30px 0 20px 0;">
                <h1 style="display:inline;font-weight:bold;font-size:32px;margin:0;padding:2px 10px 2px 0;color:#1F2345;line-height:1.2;border-bottom:4px solid #ebebeb;">{{ $title }}</h1>
            </td>
        </tr>
        @endif

        @if(!empty($plant_observations ?? ''))
        <tr>
            <td style="padding:10px 0;">
                {!! $plant_observations !!}
            </td>
        </tr>
        @endif

        @if(!empty($header_content))
        <tr>
            <td style="padding:10px 0;">
                {!! $header_content !!}
            </td>
        </tr>
        @endif

        @if(!empty($footer_content))
        <tr>
            <td style="padding:10px 0;">
                {!! $footer_content !!}
            </td>
        </tr>
        @endif

        <tr>
            <td style="padding-top:30px;border-top:1px solid #d6d8d8;font-size:13px;">
                @if(!empty($signature))
                    <div style="margin-bottom:16px;">
                        {!! $signature !!}
                    </div>
                @endif
                <p style="margin:0;color:#999999;font-size:11px;">&copy; {{ date('Y') }} Finearom. Todos los derechos reservados.</p>
            </td>
        </tr>
    </table>

</body>
</html>
