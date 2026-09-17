<?php

namespace App\Http\Requests\ProjectPotential;

/**
 * Años de Potencial a la vista: `anios[]` (uno o varios) o `anio` suelto;
 * sin ninguno, el año en curso. Ordenados y sin repetir.
 */
trait ResolvesPotentialYears
{
    protected function yearRules(): array
    {
        return [
            'anio'    => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'anios'   => ['nullable', 'array', 'max:6'],
            'anios.*' => ['integer', 'min:2000', 'max:2100'],
        ];
    }

    /** @return int[] */
    public function anios(): array
    {
        $anios = array_map('intval', (array) ($this->validated('anios') ?: [$this->validated('anio') ?? now()->year]));
        $anios = array_values(array_unique($anios));
        sort($anios);

        return $anios;
    }
}
