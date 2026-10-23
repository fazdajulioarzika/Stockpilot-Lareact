<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $unit = $this->route('unit');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('units', 'name')->ignore($unit)],
            'symbol' => ['required', 'string', 'max:20', Rule::unique('units', 'symbol')->ignore($unit)],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama satuan', 'symbol' => 'simbol'];
    }
}
