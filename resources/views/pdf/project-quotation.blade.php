<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
  body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; padding: 20px; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  h2 { font-size: 13px; margin: 20px 0 8px; border-bottom: 1px solid #ddd; padding-bottom: 4px; color: #555; }
  .header { display: flex; justify-content: space-between; margin-bottom: 20px; }
  .meta { font-size: 10px; color: #666; }
  .meta span { display: block; }
  table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
  th { background: #f3f4f6; text-align: left; padding: 6px 8px; font-size: 10px; border-bottom: 1px solid #ddd; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
  .badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 9px; font-weight: bold; }
  .ganado { background: #d1fae5; color: #065f46; }
  .perdido { background: #fee2e2; color: #991b1b; }
  .espera  { background: #fef3c7; color: #92400e; }
  .footer  { margin-top: 30px; font-size: 9px; color: #aaa; text-align: center; }
  .total-row td { font-weight: bold; background: #f9fafb; }
</style>
</head>
<body>

<div class="header">
  <div>
    <h1>Cotización: {{ $project->nombre }}</h1>
    <div class="meta">
      <span>Cliente: <strong>{{ $project->client?->client_name ?? '—' }}</strong></span>
      <span>Tipo: {{ $project->tipo }}  |  Producto: {{ $project->product?->nombre ?? '—' }}</span>
      <span>Ejecutivo: {{ $project->ejecutivo ?? '—' }}</span>
    </div>
  </div>
  <div class="meta" style="text-align:right">
    <span>Fecha: {{ now()->format('d/m/Y') }}</span>
    <span>TRM: ${{ number_format($project->trm ?? 0, 2) }}</span>
    <span>Factor: {{ $project->factor ?? '—' }}</span>
    @if($project->estado_externo)
      <span class="badge @if($project->estado_externo === 'Ganado') ganado @elseif($project->estado_externo === 'Perdido') perdido @else espera @endif">
        {{ $project->estado_externo }}
      </span>
    @endif
  </div>
</div>

@if($project->tipo === 'Desarrollo' && $items->count())
  <h2>Variantes y Propuestas</h2>
  @foreach($items as $variant)
    <p style="font-weight:bold; margin: 8px 0 4px">{{ $variant->nombre ?? 'Variante #'.$variant->id }}</p>
    <table>
      <thead>
        <tr>
          <th>Referencia Finearom</th>
          <th>Precio Snapshot (USD)</th>
          <th>Total Propuesta (USD)</th>
          <th>Total COP</th>
          <th>Definitiva</th>
        </tr>
      </thead>
      <tbody>
        @forelse($variant->proposals as $prop)
        <tr @if($prop->definitiva) class="total-row" @endif>
          <td>{{ $prop->finearomReference?->nombre ?? '—' }}</td>
          <td>${{ number_format($prop->precio_snapshot ?? 0, 2) }}</td>
          <td>${{ number_format($prop->total_propuesta ?? 0, 2) }}</td>
          <td>${{ number_format($prop->total_propuesta_cop ?? 0, 0, ',', '.') }}</td>
          <td>{{ $prop->definitiva ? '✓' : '' }}</td>
        </tr>
        @empty
        <tr><td colspan="5" style="color:#aaa">Sin propuestas</td></tr>
        @endforelse
      </tbody>
    </table>
  @endforeach

@elseif($project->tipo === 'Colección' && $items->count())
  <h2>Solicitudes de Fragancia</h2>
  <table>
    <thead>
      <tr>
        <th>#</th><th>Fragancia</th><th>Código</th><th>Casa</th><th>Cantidad</th>
      </tr>
    </thead>
    <tbody>
      @foreach($items as $i => $req)
      <tr>
        <td>{{ $i+1 }}</td>
        <td>{{ $req->fragrance?->nombre ?? '—' }}</td>
        <td>{{ $req->fragrance?->codigo ?? '—' }}</td>
        <td>{{ $req->fragancia_casa ?? '—' }}</td>
        <td>{{ $req->cantidad ?? '—' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

@elseif($project->tipo === 'Fine Fragances' && $items->count())
  <h2>Fine Fragrances</h2>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Casa</th>
        <th>Contratipo</th>
        <th>Tipo</th>
        <th>Género</th>
        <th>Familia Olfativa</th>
        <th style="text-align:right">Gramos</th>
        <th style="text-align:right">Factor</th>
        <th style="text-align:right">Precio COP</th>
      </tr>
    </thead>
    <tbody>
      @foreach($items as $i => $pf)
      <tr>
        <td>{{ $i+1 }}</td>
        <td>{{ $pf->fineFragrance?->house?->nombre ?? '—' }}</td>
        <td><strong>{{ $pf->fineFragrance?->contratipo ?? '—' }}</strong>
          @if($pf->fineFragrance?->nombre)
            <br><span style="color:#888;font-size:9px">{{ $pf->fineFragrance->nombre }}</span>
          @endif
        </td>
        <td>{{ $pf->fineFragrance?->tipo ? $pf->fineFragrance->tipo.'g' : '—' }}</td>
        <td>{{ $pf->fineFragrance?->genero ? ucfirst($pf->fineFragrance->genero) : '—' }}</td>
        <td>{{ $pf->fineFragrance?->familia_olfativa ?? '—' }}</td>
        <td style="text-align:right">{{ $pf->gramos ?? '—' }}</td>
        <td style="text-align:right">{{ $pf->margen ?? ($project->factor ?? '—') }}</td>
        <td style="text-align:right">
          @if($pf->precio_calculado)
            ${{ number_format($pf->precio_calculado, 0, ',', '.') }}
          @else
            —
          @endif
        </td>
      </tr>
      @endforeach
    </tbody>
    @php
      $totalCOP = $items->sum(fn($pf) => $pf->precio_calculado ?? 0);
    @endphp
    @if($totalCOP > 0)
    <tfoot>
      <tr class="total-row">
        <td colspan="8" style="text-align:right">Total estimado:</td>
        <td style="text-align:right">${{ number_format($totalCOP, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
    @endif
  </table>
  @php
    $notas = $items->filter(fn($pf) => $pf->notas)->values();
  @endphp
  @if($notas->count())
  <h2>Notas</h2>
  <table>
    <tbody>
      @foreach($notas as $pf)
      <tr>
        <td style="width:40%"><strong>{{ $pf->fineFragrance?->contratipo ?? '—' }}</strong></td>
        <td>{{ $pf->notas }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
@else
  <p style="color:#aaa">Sin items para mostrar.</p>
@endif

@if($project->rango_min || $project->rango_max)
<h2>Potencial</h2>
<table>
  <tr>
    <th>Rango USD/Kg</th>
    <th>Volumen Kg/año</th>
    <th>Potencial estimado USD/año</th>
  </tr>
  <tr>
    <td>{{ $project->rango_min ?? '—' }} – {{ $project->rango_max ?? '—' }}</td>
    <td>{{ $project->volumen ?? '—' }}</td>
    <td>
      @php
        $pot = (($project->rango_min + $project->rango_max) / 2) * $project->volumen;
      @endphp
      ${{ number_format($pot, 0, ',', '.') }}
    </td>
  </tr>
</table>
@endif

<div class="footer">Documento generado por Finearom · {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
