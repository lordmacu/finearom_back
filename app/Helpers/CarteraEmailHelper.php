<?php

namespace App\Helpers;

class CarteraEmailHelper
{
    /**
     * Formatea un número con el formato legacy: $1.234,56 con decimales pequeños
     * Replica la directiva @formatNumberWithSmallDecimals de legacy
     */
    private static function formatNumberWithSmallDecimals(float $number): string
    {
        $formattedNumber = number_format($number, 2, ',', '.');
        $parts = explode(',', $formattedNumber);
        $integerPart = $parts[0];
        $decimalPart = $parts[1] ?? '00';
        $showDecimals = ($decimalPart !== '00');

        $result = '$' . $integerPart;
        if ($showDecimals) {
            $result .= '<span style="font-size: 0.8em;">,' . $decimalPart . '</span>';
        }

        return $result;
    }

    /**
     * Genera la tabla HTML de facturas para emails de cartera
     */
    public static function generateInvoicesTable(array $cuentas): string
    {
        $html = '<table style="width:100%; border-collapse:collapse; margin-top:20px; border:1px solid #ddd;">';
        $html .= '<thead>';
        $html .= '<tr style="background-color:#f4f4f4;">';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Número de documento</th>';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Fecha de Emisión</th>';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Fecha de Vencimiento</th>';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center; width:30px;">Días vencidos</th>';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Valor del documento</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($cuentas as $cuenta) {
            $html .= '<tr>';

            // Número de documento
            $docArray = $cuenta['document_array'] ?? [];
            $docNumber = ($docArray['prefix'] ?? '') .
                        '<strong style="color:#0070c0;">' . ($docArray['highlight'] ?? '') . '</strong>' .
                        ($docArray['suffix'] ?? '');
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center;">' . $docNumber . '</td>';

            // Fechas
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center;">' . ($cuenta['fecha'] ?? '') . '</td>';
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center;">' . ($cuenta['vence'] ?? '') . '</td>';

            // Días vencidos (en rojo si es negativo)
            $dias = $cuenta['dias'] ?? 0;
            $diasColor = $dias > 0 ? '' : 'color:red; font-weight:bold;';
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center; ' . $diasColor . '">' . $dias . '</td>';

            // Valor del documento (en rojo si vencido)
            $saldo = self::formatNumberWithSmallDecimals($cuenta['saldo_contable'] ?? 0);
            $valorColor = $dias > 0 ? 'font-weight:bold; text-align:right;' : 'color:red; font-weight:bold; text-align:right;';
            $html .= '<td style="border:1px solid #ddd; padding:8px; ' . $valorColor . '">' . $saldo . '</td>';

            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';

        return $html;
    }

    /**
     * Genera HTML con información de balances (saldos por vencer y vencidos)
     */
    public static function generateBalanceInfo(array $data): string
    {
        $html = '';

        // Saldo por vencer (si existe)
        if (($data['total_por_vencer'] ?? 0) > 0) {
            $totalPorVencer = self::formatNumberWithSmallDecimals($data['total_por_vencer']);
            $html .= '<p>';
            $html .= 'Actualmente el saldo consolidado de cartera es: ';
            $html .= '<span style="color:#0070c0;">' . $totalPorVencer . '</span>.';
            $html .= '<br>';
            $html .= '<span style="color:#0070c0; display:block;">(' . ($data['total_por_vencer_text'] ?? '') . ')</span>';
            $html .= '</p>';
        }

        // Saldo vencido
        $totalVencidos = self::formatNumberWithSmallDecimals($data['total_vencidos'] ?? 0);
        $html .= '<p>';
        $html .= 'Y un saldo vencido de cartera de: ';
        $html .= '<span style="color:red;">' . $totalVencidos . '</span>.';
        $html .= '<br>';
        $html .= '<span style="color:red; display:block;">(' . ($data['total_vencidos_text'] ?? '') . ')</span>';
        $html .= '</p>';

        return $html;
    }

    /**
     * Genera la tabla HTML de órdenes bloqueadas
     */
    public static function generateBlockedOrdersTable(array $products): string
    {
        $html = '<table style="width:100%; border-collapse:collapse; margin-top:20px; border:1px solid #ddd;">';
        $html .= '<thead>';
        $html .= '<tr style="background-color:#f4f4f4;">';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Número de orden</th>';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Referencia</th>';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Precio unidad</th>';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Fecha despacho</th>';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Cant.</th>';
        $html .= '<th style="border:1px solid #ddd; padding:8px; text-align:center;">Precio total</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($products as $product) {
            $html .= '<tr>';
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center;">' . ($product->order_consecutive ?? '') . '</td>';
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center;">' . ($product->product_name ?? '') . '</td>';
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center;">' . ($product->price ?? '') . '</td>';

            // Fecha de despacho
            $dispatchDate = '';
            if (isset($product->dispatch_date)) {
                $dispatchDate = \Carbon\Carbon::parse($product->dispatch_date)->locale('es')->isoFormat('MMM DD/YYYY');
            }
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center;">' . $dispatchDate . '</td>';

            // Cantidad
            $quantity = number_format($product->quantity ?? 0, 2, '.', '');
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center;">' . $quantity . '</td>';

            // Precio total
            $total = ($product->quantity ?? 0) * ($product->price ?? 0);
            $totalFormatted = self::formatNumberWithSmallDecimals($total);
            $html .= '<td style="border:1px solid #ddd; padding:8px; text-align:center;">' . $totalFormatted . ' USD</td>';

            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';

        return $html;
    }
}
