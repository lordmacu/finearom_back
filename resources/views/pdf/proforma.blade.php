<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura Proforma</title>
    <style>
        /* Configuración específica para PDF */
        @page {
            size: A4;
            margin: 15mm;
        }

        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            font-size: 12px;
            line-height: 1.3;
            color: #333;
        }

        .container {
            width: 100%;
            max-width: 100%;
            background-color: white;
        }

        .header {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 15px;
        }

        .company-info {
            display: table-cell;
            width: 60%;
            vertical-align: top;
        }

        .invoice-info {
            display: table-cell;
            width: 40%;
            vertical-align: top;
            text-align: right;
        }

        .logo-section {
            margin-bottom: 10px;
        }

        .logo {
            display: inline-block;
            width: 35px;
            height: 35px;
            background-color: #2c5aa0;
            border-radius: 50%;
            color: white;
            font-weight: bold;
            font-size: 18px;
            text-align: center;
            line-height: 35px;
            margin-right: 10px;
            vertical-align: middle;
        }

        .company-name {
            display: inline-block;
            font-size: 20px;
            color: #333;
            margin: 0;
            vertical-align: middle;
            font-weight: bold;
        }

        .company-details {
            font-size: 11px;
            color: #666;
            line-height: 1.4;
            margin-top: 8px;
        }

        .company-details p {
            margin: 2px 0;
        }

        .invoice-title {
            font-size: 16px;
            color: #2c5aa0;
            font-weight: bold;
            margin: 0 0 5px 0;
        }

        .invoice-number {
            font-size: 12px;
            color: #666;
            margin: 0;
        }

        .client-info {
            display: table;
            width: 100%;
            margin-bottom: 20px;
        }

        .client-details {
            display: table-cell;
            width: 50%;
            vertical-align: top;
        }

        .invoice-details {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }

        .client-details h3,
        .invoice-details h3 {
            font-size: 14px;
            margin: 0 0 8px 0;
            color: #2c5aa0;
        }

        .client-details p,
        .invoice-details p {
            margin: 3px 0;
            font-size: 11px;
        }

        .validity-notice {
            margin-top: 15px;
            padding: 10px;
            background-color: #f9f9f9;
            border-left: 4px solid #2c5aa0;
            font-size: 11px;
            color: #333;
        }

        .validity-notice p {
            margin: 0;
            font-weight: normal;
        }

        .validity-notice strong {
            color: #2c5aa0;
        }


        .products-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10px;
        }

        .products-table th {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            padding: 6px 4px;
            text-align: left;
            font-weight: bold;
        }

        .products-table td {
            border: 1px solid #ddd;
            padding: 6px 4px;
            vertical-align: top;
        }

        .footer-info {
            display: table;
            width: 100%;
            margin-top: 15px;
        }

        .additional-info {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            font-size: 11px;
        }

        .totals-section {
            display: table-cell;
            width: 50%;
            vertical-align: top;
            text-align: right;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            margin-left: auto;
            max-width: 250px;
        }

        .totals-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #eee;
        }

        .total-row {
            background-color: #f0f8ff;
            border-top: 2px solid #2c5aa0;
        }

        .total-amount {
            font-weight: bold;
            font-size: 12px;
            color: #2c5aa0;
        }

        .disclaimer {
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e0e0e0;
            font-size: 10px;
            color: #666;
            text-align: center;
        }

        /* Estilos específicos para alineación */
        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-left {
            text-align: left;
        }

        .font-bold {
            font-weight: bold;
        }

        /* Evitar saltos de página en elementos críticos */
        .header,
        .client-info,
        .totals-table {
            page-break-inside: avoid;
        }

        /* Optimización para impresión */
        @media print {
            .container {
                margin: 0;
                box-shadow: none;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                <div class="logo-section">
                    <img style="height: 35px; vertical-align: middle; margin-right: 10px;" src="{{ $logoUrl }}"
                        alt="Finearom">

                    <h1 class="company-name">{{ $company['name'] ?? 'FINEAROM SAS' }}</h1>
                </div>
                <div class="company-details">
                    <p><strong>NIT:</strong> {{ $company['nit'] ?? '900.220.672-8' }}</p>
                    <p>{{ $company['address'] ?? 'CRA 50 134 D 31' }}</p>
                    <p>{{ $company['city'] ?? 'BOGOTÁ - COLOMBIA' }}</p>
                    <p>{{ $company['phone'] ?? '57 1 6150035' }}</p>
                    <p>{{ $company['email'] ?? 'servicio.cliente@finearom.com' }}</p>
                </div>
            </div>
            <div class="invoice-info">
                <h2 class="invoice-title">PROFORMA</h2>
                <p class="invoice-number"><strong>{{ $invoice['number'] ?? '' }}</strong></p>
            </div>
        </div>

        <!-- Client and Invoice Info -->
        <div class="client-info">
            <div class="client-details">
                <h3>Cliente</h3>
                <p><strong>{{ $client['name'] ?? '' }}</strong></p>
                <p><strong>NIT:</strong> {{ $client['nit'] ?? '' }}</p>
                <p><strong>Dirección:</strong> {{ $client['address'] ?? '' }}</p>
                <p><strong>Ciudad:</strong> {{ $client['city'] ?? '' }}</p>
                <p><strong>Teléfono:</strong> {{ $client['phone'] ?? '' }}</p>
                <p><strong>Correo:</strong> {{ $client['email'] ?? '' }}</p>
            </div>
            <div class="invoice-details">
                <p><strong>Orden de compra:</strong> {{ $orden_compra ?? '' }}</p>
            </div>
        </div>

        <!-- Products Table -->
        <table class="products-table">
            <thead>
                <tr>
                    <th width="6%">Ítem</th>
                    <th width="12%">Código</th>
                    <th width="35%">Descripción</th>
                    <th width="8%">Unidad</th>
                    <th width="10%">Cantidad</th>
                    <th width="14%">Valor Unitario</th>
                    <th width="15%">Valor Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items ?? [] as $item)
                    <tr>
                        <td class="text-center">{{ $item['item'] }}</td>
                        <td>{{ $item['code'] }}</td>
                        <td>{{ $item['description'] }}</td>
                        <td class="text-center">{{ $item['unit'] }}</td>
                        <td class="text-center">{{ number_format($item['quantity'], 2) }}</td>
                        <td class="text-right">{{ number_format($item['unit_price'], 2) }}</td>
                        <td class="text-right font-bold">${{ number_format($item['total'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Footer with totals -->
        <div class="footer-info">
            <div class="additional-info">
                <p><strong>Total Ítems:</strong> {{ count($items ?? []) }}</p>
                <p><strong>TRM:</strong> Del día {{ $fecha_trm }} @if(isset($trm)) POR ${{ number_format($trm, 2) }} @endif</p>
                <p><strong>Forma de Pago:</strong> Favor consignar en: CTA CTE No. 12279201300 Bancolombia</p>
            </div>

            <div class="totals-section">
                <table class="totals-table">
                    <tr>
                        <td>SUBTOTAL</td>
                        <td class="text-right font-bold">${{ number_format($totals['subtotal'] ?? 0, 2) }}</td>
                    </tr>

                    @if (isset($totals['flete']) && $totals['flete'] > 0)
                        <tr>
                            <td>FLETE</td>
                            <td class="text-right font-bold">${{ number_format($totals['flete'] ?? 0, 2) }}</td>
                        </tr>
                    @endif

                    @if (($client_rates['iva'] ?? 0) > 0)
                        <tr>
                            <td>IVA ({{ $client_rates['iva'] }}%)</td>
                            <td class="text-right font-bold">${{ number_format($totals['iva'] ?? 0, 2) }}</td>
                        </tr>
                    @endif

                    @if (($totals['rete_ica'] ?? 0) > 0)
                        <tr>
                            <td>RETE ICA @if(isset($client_rates['ica'])) ({{ $client_rates['ica'] }}%) @endif</td>
                            <td class="text-right font-bold">${{ number_format($totals['rete_ica'] ?? 0, 2) }}</td>
                        </tr>
                    @endif

                    @if (($totals['rete_iva'] ?? 0) > 0)
                        <tr>
                            <td>RETE IVA @if(isset($client_rates['reteiva'])) ({{ $client_rates['reteiva'] }}%) @endif</td>
                            <td class="text-right font-bold">${{ number_format($totals['rete_iva'] ?? 0, 2) }}</td>
                        </tr>
                    @endif

                    @if (($totals['rete_fte'] ?? 0) > 0)
                        <tr>
                            <td>RTE. FTE @if(isset($client_rates['retefuente'])) ({{ $client_rates['retefuente'] }}%) @endif</td>
                            <td class="text-right font-bold">${{ number_format($totals['rete_fte'] ?? 0, 2) }}</td>
                        </tr>
                    @endif

                    <tr class="total-row">
                        <td class="total-amount">TOTAL ({{ $currency ?? 'USD' }})</td>
                        <td class="text-right total-amount">${{ number_format($totals['total'] ?? 0, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>
        <div class="validity-notice">
            <p><strong>VALIDEZ:</strong> Esta proforma es válida por 8 días hábiles a partir de su emisión.</p>
        </div>

        <!-- Disclaimer -->
        <div class="disclaimer">
            <p>Esta es una factura proforma con fines informativos. No constituye una factura oficial.</p>
        </div>
    </div>
</body>

</html>
