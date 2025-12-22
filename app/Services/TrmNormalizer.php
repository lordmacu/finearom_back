<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Servicio para normalizar y validar valores de TRM
 * Centraliza toda la lógica de normalización y validación de TRM
 */
class TrmNormalizer
{
    /**
     * Valor mínimo considerado válido para TRM
     */
    const MIN_VALID_TRM = 3800;

    /**
     * Valor máximo considerado válido para TRM
     */
    const MAX_VALID_TRM = 10000;

    /**
     * Normaliza un valor de TRM (convierte a float y valida rango)
     *
     * @param mixed $trmValue Valor a normalizar
     * @return float Valor normalizado o 0 si es inválido
     */
    public function normalize($trmValue): float
    {
        try {
            // Convertir a float
            $normalized = $this->convertToFloat($trmValue);

            // Validar rango
            if (!$this->isValidRange($normalized)) {
                return 0;
            }

            return $normalized;
        } catch (Exception $e) {
            Log::warning("Error normalizando TRM: " . $e->getMessage(), [
                'original_value' => $trmValue,
                'type' => gettype($trmValue)
            ]);
            return 0;
        }
    }

    /**
     * Verifica si un valor de TRM está en el rango válido
     *
     * @param float $trmValue
     * @return bool
     */
    public function isValidRange(float $trmValue): bool
    {
        return $trmValue >= self::MIN_VALID_TRM && $trmValue <= self::MAX_VALID_TRM;
    }

    /**
     * Verifica si un valor de TRM es considerado "por defecto" (inválido)
     *
     * @param mixed $trmValue
     * @return bool
     */
    public function isDefaultTrm($trmValue): bool
    {
        $normalized = $this->normalize($trmValue);
        return $normalized == 0 || $normalized < self::MIN_VALID_TRM;
    }

    /**
     * Normaliza TRM con información adicional sobre si es por defecto
     * (Replica la lógica exacta de analyzeClientsByStatus)
     *
     * @param mixed $trmValue Valor TRM del parcial
     * @return array ['value' => float, 'is_default' => bool, 'is_valid' => bool]
     */
    public function normalizeWithFlags($trmValue): array
    {
        try {
            $normalized = $this->convertToFloat($trmValue);
        } catch (Exception $e) {
            return [
                'value' => 0,
                'is_default' => true,
                'is_valid' => false,
                'error' => $e->getMessage()
            ];
        }

        $isDefault = false;
        $isValid = true;

        // Lógica exacta de analyzeClientsByStatus
        if ($normalized == 0) {
            $isDefault = true;
            $isValid = false;
        }

        if ($normalized < self::MIN_VALID_TRM) {
            $isDefault = true;
            $isValid = false;
        }

        return [
            'value' => $normalized,
            'is_default' => $isDefault,
            'is_valid' => $isValid
        ];
    }

    /**
     * Normaliza múltiples valores de TRM
     *
     * @param array $trmValues
     * @return array
     */
    public function normalizeMultiple(array $trmValues): array
    {
        return array_map([$this, 'normalize'], $trmValues);
    }

    /**
     * Obtiene estadísticas de un conjunto de valores TRM
     *
     * @param array $trmValues
     * @return array
     */
    public function getStatistics(array $trmValues): array
    {
        $normalized = $this->normalizeMultiple($trmValues);
        $valid = array_filter($normalized, [$this, 'isValidRange']);
        $invalid = array_filter($normalized, fn($v) => !$this->isValidRange($v));

        return [
            'total_count' => count($trmValues),
            'valid_count' => count($valid),
            'invalid_count' => count($invalid),
            'valid_percentage' => count($trmValues) > 0 ? round((count($valid) / count($trmValues)) * 100, 2) : 0,
            'average_valid' => count($valid) > 0 ? round(array_sum($valid) / count($valid), 2) : 0,
            'min_valid' => count($valid) > 0 ? min($valid) : 0,
            'max_valid' => count($valid) > 0 ? max($valid) : 0,
        ];
    }

    /**
     * Valida si un valor TRM está dentro de un rango específico
     *
     * @param mixed $trmValue
     * @param float $minValue
     * @param float $maxValue
     * @return bool
     */
    public function isInRange($trmValue, float $minValue, float $maxValue): bool
    {
        $normalized = $this->normalize($trmValue);
        return $normalized >= $minValue && $normalized <= $maxValue;
    }

    /**
     * Formatea un valor TRM para mostrar
     *
     * @param mixed $trmValue
     * @param int $decimals
     * @return string
     */
    public function format($trmValue, int $decimals = 2): string
    {
        $normalized = $this->normalize($trmValue);

        if ($normalized == 0) {
            return 'N/A';
        }

        return number_format($normalized, $decimals, '.', ',');
    }

    /**
     * Convierte cualquier valor a float de forma segura
     *
     * @param mixed $value
     * @return float
     * @throws Exception
     */
    private function convertToFloat($value): float
    {
        // Si ya es numeric, convertir directamente
        if (is_numeric($value)) {
            return (float) $value;
        }

        // Si es string, limpiar y convertir
        if (is_string($value)) {
            // Remover espacios, comas y otros caracteres
            $cleaned = preg_replace('/[^\d.-]/', '', trim($value));

            if (is_numeric($cleaned)) {
                return (float) $cleaned;
            }
        }

        // Si es null o empty, retornar 0
        if (empty($value)) {
            return 0;
        }

        throw new Exception("Cannot convert value to float: " . gettype($value));
    }

    /**
     * Valida que un conjunto de TRMs sean consistentes (no muy diferentes entre sí)
     *
     * @param array $trmValues
     * @param float $tolerancePercentage Porcentaje de tolerancia (ej: 5.0 para 5%)
     * @return array
     */
    public function validateConsistency(array $trmValues, float $tolerancePercentage = 5.0): array
    {
        $normalized = array_filter($this->normalizeMultiple($trmValues), [$this, 'isValidRange']);

        if (count($normalized) < 2) {
            return [
                'is_consistent' => true,
                'message' => 'Insufficient data for consistency check',
                'outliers' => []
            ];
        }

        $average = array_sum($normalized) / count($normalized);
        $tolerance = $average * ($tolerancePercentage / 100);
        $outliers = [];

        foreach ($normalized as $index => $value) {
            if (abs($value - $average) > $tolerance) {
                $outliers[] = [
                    'index' => $index,
                    'value' => $value,
                    'difference_percentage' => round((abs($value - $average) / $average) * 100, 2)
                ];
            }
        }

        return [
            'is_consistent' => empty($outliers),
            'average' => round($average, 2),
            'tolerance' => round($tolerance, 2),
            'outliers' => $outliers,
            'outlier_count' => count($outliers)
        ];
    }

    /**
     * Obtiene información de configuración del normalizador
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'min_valid_trm' => self::MIN_VALID_TRM,
            'max_valid_trm' => self::MAX_VALID_TRM,
            'version' => '1.0.0'
        ];
    }
}
