<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        // Kode gudang selalu huruf besar agar "gd-a" dan "GD-A" tidak lolos sebagai dua kode.
        if (is_string($this->code)) {
            $this->merge(['code' => strtoupper(trim($this->code))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:30', 'unique:warehouses,code'],
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
