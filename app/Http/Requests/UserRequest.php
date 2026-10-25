<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-users') === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        $target = $this->route('user'); // ada = ubah, tidak ada = buat baru

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target)],
            'role' => ['required', Rule::enum(Role::class)],
            'is_active' => ['required', 'boolean'],
            'password' => [$target ? 'nullable' : 'required', 'string', Password::defaults()],
            'warehouse_ids' => ['nullable', 'array', 'max:100'],
            'warehouse_ids.*' => ['integer', 'distinct', Rule::exists('warehouses', 'id')],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'email',
            'role' => 'role',
            'password' => 'password',
            'warehouse_ids' => 'gudang',
        ];
    }
}
