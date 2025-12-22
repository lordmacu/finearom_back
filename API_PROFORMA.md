# API de Proforma

Documentación del API REST para el módulo de **Proforma** (actualización masiva de datos tributarios de clientes).

## Información General

- **Base URL**: `/api/proforma`
- **Autenticación**: Requerida (Sanctum Token)
- **Formato**: JSON y `multipart/form-data` para upload

## Permisos

- Requiere permiso: `proforma upload`

---

## Endpoint

### Subir y procesar archivo de proforma

Procesa un archivo Excel con datos tributarios de clientes y actualiza la base de datos.

**Endpoint**: `POST /api/proforma/upload`

**Content-Type**: `multipart/form-data`

**Body (form-data)**:
- `file`: Archivo Excel (.xlsx, .xls) - Máximo 10MB

**Formato del archivo Excel**:

| Columna | Campo | Descripción | Formato |
|---------|-------|-------------|---------|
| A | NIT | Número de identificación tributaria | String |
| B | Cliente | Nombre del cliente | String |
| C | Venta de Contado | Tipo de venta | String |
| D | Ciudad | Ciudad del cliente | String |
| E | Tipo Contribuyente | Clasificación tributaria | String |
| F | Zona Franca | Indicador de zona franca | "X" para Sí, vacío para No |
| G | IVA | Porcentaje de IVA | "IVA:19" o "19" |
| H | RETEFUENTE | Porcentaje de retención en la fuente | "RETE FUENTE:2.5" o "2.5" |
| I | RETE IVA | Porcentaje de retención de IVA | "RETE IVA:1.104" o "1.104" |
| J | ICA | Porcentaje de ICA | "ICA:1.10" o "1.10" |

**Respuesta Exitosa** (200 OK):
```json
{
  "success": true,
  "message": "Archivo procesado exitosamente. 15 clientes actualizados.",
  "data": [
    {
      "nit": "900123456",
      "nombre_cliente": "EMPRESA EJEMPLO S.A.S",
      "venta_de_contado": "NO",
      "ciudad": "BOGOTA",
      "tipo_contribuyente": "GRAN CONTRIBUYENTE",
      "zona_franca": "X",
      "iva": 19,
      "retefuente": 2.5,
      "reteiva": 1.104,
      "ica": 1.10,
      "actualizado_proforma": true
    }
  ],
  "meta": {
    "total_rows": 15,
    "updated_count": 15,
    "not_found_nits": []
  }
}
```

**Campos actualizados en la tabla `clients`**:
- `venta_de_contado`
- `ciudad`
- `tipo_contribuyente`
- `zona_franca` (convertido a boolean: true si es "X", false si no)
- `iva` (float)
- `retefuente` (float)
- `reteiva` (float)
- `ica` (float)
- `actualizado_proforma` (true)

**Errores Comunes**:

```json
{
  "success": false,
  "message": "El archivo debe ser de tipo Excel (.xlsx o .xls)"
}
```

```json
{
  "success": false,
  "message": "Error al procesar el archivo: [detalle del error]"
}
```

---

## Notas de Implementación

### Parseo de valores tributarios

El sistema soporta dos formatos para los valores tributarios:

1. **Formato con etiqueta**: `"IVA:19"`, `"RETE FUENTE:2.5"`
2. **Formato numérico directo**: `"19"`, `"2.5"`

Expresiones regulares utilizadas:
- IVA: `/IVA:(\d+(\.\d+)?)/`
- RETEFUENTE: `/RETE FUENTE:(\d+(\.\d+)?)/`
- RETE IVA: `/RETE IVA:(\d+(\.\d+)?)/`
- ICA: `/ICA:(\d+(\.\d+)?)/`

### Zona Franca

- Se considera `true` si el valor de la columna es "X" (case-insensitive)
- Se considera `false` en cualquier otro caso

### Manejo de NITs no encontrados

Si un NIT del archivo no existe en la base de datos:
- Se incluye en el array `data` de la respuesta
- **NO** se actualiza (obviamente)
- Se agrega a `meta.not_found_nits`

El frontend puede mostrar una advertencia con estos NITs.

---

## Ejemplo de uso (JavaScript)

```javascript
import { proformaService } from '@/services/proformaService'

const file = event.target.files[0]

try {
  const response = await proformaService.uploadProforma(file)

  console.log(`Procesados: ${response.meta.total_rows}`)
  console.log(`Actualizados: ${response.meta.updated_count}`)

  if (response.meta.not_found_nits.length > 0) {
    console.warn('NITs no encontrados:', response.meta.not_found_nits)
  }
} catch (error) {
  console.error('Error:', error.response.data.message)
}
```

---

## Migración desde Legacy

**Legacy**: `POST /admin/proforma/upload`
- Controller: `App\Http\Controllers\Admin\CarteraController@proformaUpload`
- Ubicación: `legacy/app/Http/Controllers/Admin/CarteraController.php` líneas 37-90

**Cambios en la nueva API**:
- ✅ Misma lógica de parseo
- ✅ Mismos campos actualizados
- ✅ Validación mejorada con FormRequest
- ✅ Respuesta más estructurada con meta información
- ✅ Mejor manejo de errores
