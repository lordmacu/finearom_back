<?php

namespace App\Services\Vanna;

use Illuminate\Support\Facades\DB;

/**
 * Valida candidatos SQL propuestos (por harvesting de historial o generación
 * con LLM) antes de aceptarlos en el corpus de entrenamiento de Vanna:
 * deben ser un SELECT/WITH de solo lectura, sin construcciones peligrosas,
 * y deben ejecutar realmente contra la base de datos.
 */
class SqlCandidateValidator
{
    /**
     * Palabras clave de escritura/DDL/peligrosas, sensibles a límite de palabra
     * (no a espacios de cola) para evitar falsos negativos con saltos de línea,
     * paréntesis o fin de cadena inmediatamente después de la palabra clave.
     */
    private const FORBIDDEN_KEYWORDS = '/\b(DROP|DELETE|UPDATE|INSERT|ALTER|CREATE|TRUNCATE|REPLACE|GRANT|EXEC(UTE)?)\b/i';

    private const FORBIDDEN_INTO_OUTFILE = '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i';

    private const FORBIDDEN_LOAD_FILE = '/\bLOAD_FILE\b/i';

    /**
     * True solo si el SQL comienza con SELECT o WITH (permitiendo comentarios
     * `--` y espacios en blanco al inicio) y no contiene ninguna construcción
     * de escritura o peligrosa.
     */
    public function isSafeSelect(string $sql): bool
    {
        $head = preg_replace('/^(\s*--[^\n]*\n)*\s*/', '', $sql) ?? $sql;

        if (!preg_match('/^(WITH|SELECT)\s/is', $head)) {
            return false;
        }

        if (preg_match(self::FORBIDDEN_KEYWORDS, $sql)) {
            return false;
        }

        if (preg_match(self::FORBIDDEN_INTO_OUTFILE, $sql)) {
            return false;
        }

        if (preg_match(self::FORBIDDEN_LOAD_FILE, $sql)) {
            return false;
        }

        return true;
    }

    /**
     * Ejecuta el SQL envuelto como subconsulta de solo lectura; true solo si
     * no lanza ninguna excepción.
     */
    public function executesReadOnly(string $sql): bool
    {
        try {
            DB::select('SELECT * FROM (' . rtrim($sql, "; \n\t") . ') AS _probe LIMIT 1');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Normaliza el SQL (minúsculas + espacios colapsados) para deduplicación.
     */
    public function normalize(string $sql): string
    {
        return preg_replace('/\s+/', ' ', strtolower(trim($sql)));
    }
}
