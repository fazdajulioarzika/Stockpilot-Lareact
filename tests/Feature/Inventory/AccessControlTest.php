<?php

use App\Enums\Role;
use App\Models\Category;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class); // hapus baris ini jika tests/Pest.php sudah memasangnya

function userWithRole(Role $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user->fresh();
}

it('lets every role read master data', function (Role $role) {
    $this->actingAs(userWithRole($role))->get('/categories')->assertOk();
})->with([Role::Admin, Role::Manager, Role::WarehouseStaff]);

it('lets admin create master data', function () {
    $this->actingAs(userWithRole(Role::Admin))
        ->post('/categories', ['name' => 'Elektronik'])
        ->assertRedirect();

    expect(Category::where('name', 'Elektronik')->exists())->toBeTrue();
});

it('forbids non-admin from writing master data', function (Role $role) {
    $this->actingAs(userWithRole($role))
        ->post('/categories', ['name' => 'Elektronik'])
        ->assertForbidden();

    expect(Category::count())->toBe(0);
})->with([Role::Manager, Role::WarehouseStaff]);

it('forbids non-admin from toggling a warehouse', function () {
    $warehouse = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);

    $this->actingAs(userWithRole(Role::WarehouseStaff))
        ->patch("/warehouses/{$warehouse->id}/toggle-active")
        ->assertForbidden();

    expect($warehouse->fresh()->is_active)->toBeTrue();
});

it('scopes warehouse access by role and assignment', function () {
    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);
    $b = Warehouse::create(['code' => 'GD-B', 'name' => 'B']);

    $staff = userWithRole(Role::WarehouseStaff);
    $staff->warehouses()->attach($a->id);

    expect($staff->canAccessWarehouse($a->id))->toBeTrue()
        ->and($staff->canAccessWarehouse($b->id))->toBeFalse()
        ->and(Warehouse::accessibleBy($staff)->pluck('id')->all())->toBe([$a->id]);

    $manager = userWithRole(Role::Manager);
    expect($manager->canAccessWarehouse($b->id))->toBeTrue()
        ->and(Warehouse::accessibleBy($manager)->count())->toBe(2);
});
it('redirects guests away from master data pages', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/categories', '/units', '/products', '/suppliers', '/warehouses']);

it('rejects guests from writing master data', function () {
    $this->post('/categories', ['name' => 'Elektronik'])->assertRedirect('/login');

    expect(\App\Models\Category::count())->toBe(0);
});
