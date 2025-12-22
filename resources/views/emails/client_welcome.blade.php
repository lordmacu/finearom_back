@extends('emails.layout_centered_logo')

@section('title', 'Bienvenido a FINEAROM')

@section('content')
    <p style="text-align:center; margin-top:20px;"><strong>Estimado/a {{ $client->client_name }}.</strong></p>
    <div class="email-title-container" style="text-align:center;">
        <h1 class="email-title">¡Es un honor darte la bienvenida a FINEAROM!</h1>
    </div>

    <div>
        Estamos encantados de convertirnos en un aliado estratégico en la perfumación exitosa de sus nuevos productos. Nuestro
        compromiso con la calidad, la innovación exclusiva y el servicio personalizado será siempre nuestro principal y único
        objetivo: agregar valor a sus marcas.
    </div>

    <p>Nos mueve el propósito de ser un proveedor integral. Nuestras propuestas están basadas en una exploración constante del mercado, sus tendencias, la visualización y concreción de oportunidades dentro de su consumidor objetivo.</p>
<br>
    <p><strong>Para garantizar una comunicación efectiva y fluida, te compartimos los datos de contacto de nuestros equipos especializados:</strong></p>

    <table style="width:100%; border-collapse:collapse; margin-top:12px; text-align:center; margin:auto; border:0;">
        <tr>
            
            <td style="padding:10px; border:0;">
                <img src="{{ url('/images/gestioncartera.jpg') }}" alt="Gestión de Cartera" style="width:90px; height:auto; display:block; margin:0 auto 8px;">
                <p style="margin:0; text-align:center;">
                     <a href="mailto:facturacion@finearom.com">facturacion@finearom.com</a>
                </p>
            </td>

             <td style="padding:10px; border:0;">
                <img src="{{ url('/images/comercial.jpg') }}" alt="Ejecutiva Comercial" style="width:90px; height:auto; display:block; margin:0 auto 8px;">
                <p style="margin:0; text-align:center;">
                    <strong>Ejecutiva Comercial</strong><br>
                    {{ $executiveName ?? 'Equipo Comercial' }}<br>
                    @if ($executivePhone)
                         <a href="tel:{{ $executivePhone }}">{{ $executivePhone }}</a><br>
                    @endif
                    @if ($executiveEmail)
                       <a href="mailto:{{ $executiveEmail }}">{{ $executiveEmail }}</a>
                    @endif
                </p>
            </td>
            <td style="padding:10px; border:0;">
                <img src="{{ url('/images/logistica.jpg') }}" alt="Logística y Despachos" style="width:90px; height:auto; display:block; margin:0 auto 8px;">
                <p style="margin:0; text-align:center;">
                      <a href="mailto:analista.operaciones@finearom.com">analista.operaciones@finearom.com</a>
                </p>
            </td>
        </tr>
    </table>

    <p>Si tienes alguna duda, no dudes en contactarnos. Estamos aquí para ayudarte.</p>
@endsection
