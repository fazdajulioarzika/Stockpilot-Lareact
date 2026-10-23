<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['code' => 'GD-JKT', 'name' => 'Gudang Jakarta', 'address' => 'Jakarta'],
            ['code' => 'GD-SBY', 'name' => 'Gudang Surabaya', 'address' => 'Surabaya'],
        ] as $w) {
            Warehouse::firstOrCreate(['code' => $w['code']], $w);
        }
    }
}
