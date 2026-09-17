<?php

namespace App\Http\Requests\ProjectPotential;

use Illuminate\Foundation\Http\FormRequest;

class ProjectPotentialShowRequest extends FormRequest
{
    use ResolvesPotentialYears;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->yearRules();
    }
}
