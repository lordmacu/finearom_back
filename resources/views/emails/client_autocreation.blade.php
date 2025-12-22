@extends('emails.layout_centered_logo')

@section('title', 'Solicitud de Autocreación - Finearom')

@section('content')
    <p><strong>ESTIMADO/A {{ strtoupper($client->client_name) }}:</strong></p>
    <p>Nos alegra dar este primer paso hacia el inicio de una alianza comercial sólida y beneficiosa para ambas partes.</p>
    <p>Con el fin de avanzar en el proceso de vinculación como cliente/proveedor, le solicitamos amablemente que diligencie el formulario de autocreación en nuestro sistema.</p>
    <p></p>
    <div style="margin: 24px 0; text-align:center;">
        <a href="{{ $link }}" style="
            display:inline-block;
            background:#121e45;
            color:#ffffff !important;
            padding:10px 18px;
            text-decoration:none;
            border-radius:8px;
            font-weight:bold; 
                padding-bottom: 5px;
                    padding-top: 5px;
        ">
            Haciendo click aquí
        </a>
    </div>
   <p></p>
    <div style="margin-top: 12px; background:#f8f9fa; padding:14px; border-radius:6px; font-size:12px; word-break:break-all;">
        <span>Si el botón no funciona, copie y pegue este enlace en su navegador:</span><br>
        <a href="{{ $link }}"><strong>{{ $link }}</strong></a>
    </div>
<br><br>
    <p style="margin-top: 24px;">Este proceso nos permitirá contar con la información más actualizada y completa de su organización, lo que facilitará una atención más ágil, precisa y oportuna desde cada una de nuestras áreas.</p>
    <p>Agradecemos de antemano su colaboración y quedamos atentos a cualquier inquietud o comentario que pueda tener durante el proceso.</p>
@endsection

@section('signature')
    <table style="width:100%; border-collapse:collapse; margin-top:8px; border:0;">
        <tr>
            <td style="vertical-align:middle; padding:0; border:0; width:140px;">
                <img src="https://ordenes.finearom.co/images/logo.png" alt="Finearom Logo" style="width:90px; height:auto;">
            </td>
            <td style="vertical-align:middle; font-size:14px; padding:0 8px 0 0; border:0;width: 160px;">
                <div style="font-weight:bold;">Mónica Castaño</div>
                <div>Dirección comercial</div>
            </td>
            <td style="vertical-align:middle; font-size:14px; padding:0 0 0 8px; border:0;">
                <div style="border-left:1px solid #d6d8d8; padding-left:24px;">
                    <div style="display:flex; align-items:center; gap:6px; margin-bottom:4px;">
                        <span style="font-weight:bold;">+57 317 433 5096</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:6px;">
                        <a href="mailto:monica.castano@finearom.com" style="text-decoration:none; color:#121e45; font-weight:bold;">monica.castano@finearom.com</a>
                    </div>
                </div>
            </td>
        </tr>
    </table>
@endsection
