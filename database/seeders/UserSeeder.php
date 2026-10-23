<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Akun demo dengan password sederhana: jangan dijalankan di production.
        if (app()->isProduction()) {
            return;
        }

        $accounts = [
            ['Admin Demo', 'admin@stockpilot.test', Role::Admin],
            ['Manager Demo', 'manager@stockpilot.test', Role::Manager],
            ['Staff Jakarta', 'staff@stockpilot.test', Role::WarehouseStaff],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $name,
                'password' => Hash::make('password'),
                'role' => $role,
                'email_verified_at' => now(),
            ])->save();
        }

        // Staff hanya ditugaskan ke gudang Jakarta.
        $staff = User::where('email', 'staff@stockpilot.test')->first();
        $jakarta = Warehouse::where('code', 'GD-JKT')->first();

        if ($staff && $jakarta) {
            $staff->warehouses()->syncWithoutDetaching([$jakarta->id]);
        }
    }
}
