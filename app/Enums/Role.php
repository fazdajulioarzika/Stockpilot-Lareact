<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case WarehouseStaff = 'warehouse_staff';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Manager => 'Manager',
            self::WarehouseStaff => 'Warehouse Staff',
        };
    }
}
