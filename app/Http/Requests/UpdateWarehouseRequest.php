<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:30',
                Rule::unique('warehouses', 'code')->ignore($this->route('warehouse')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'kode gudang', 'name' => 'nama gudang', 'address' => 'alamat'];
    }
}
