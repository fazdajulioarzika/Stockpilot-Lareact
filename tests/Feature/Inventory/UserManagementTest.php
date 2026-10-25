<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

const UM_PASSWORD = 'Str0ng-Passw0rd!';

function umUser(Role $role, array $attributes = []): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role, ...$attributes])->save();

    return $user->fresh();
}

function umPayload(array $override = []): array
{
    return [
        'name' => 'Pengguna Baru',
        'email' => 'baru@stockpilot.test',
        'password' => UM_PASSWORD,
        'role' => 'warehouse_staff',
        'is_active' => true,
        'warehouse_ids' => [],
        ...$override,
    ];
}

it('keeps the users page away from non-admin roles', function (Role $role) {
    $user = umUser($role);
    $target = umUser(Role::WarehouseStaff);

    $this->actingAs($user)->get('/users')->assertForbidden();
    $this->actingAs($user)->post('/users', umPayload())->assertForbidden();
    $this->actingAs($user)->put("/users/{$target->id}", umPayload(['role' => 'admin']))->assertForbidden();

    expect($target->fresh()->role)->toBe(Role::WarehouseStaff)
        ->and(User::where('email', 'baru@stockpilot.test')->exists())->toBeFalse();
})->with([Role::Manager, Role::WarehouseStaff]);

it('lets an admin create a user with a role and warehouses and audits it', function () {
    $admin = umUser(Role::Admin);
    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);

    $this->actingAs($admin)
        ->post('/users', umPayload(['email' => 'BARU@StockPilot.test', 'warehouse_ids' => [$a->id]]))
        ->assertRedirect();

    $user = User::where('email', 'baru@stockpilot.test')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe(Role::WarehouseStaff)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check(UM_PASSWORD, $user->password))->toBeTrue()
        ->and($user->warehouses->pluck('id')->all())->toBe([$a->id]);

    $log = AuditLog::where('action', 'user.created')->first();

    expect($log->user_id)->toBe($admin->id)
        ->and($log->entity_id)->toBe($user->id)
        ->and(json_encode($log->metadata))->not->toContain(UM_PASSWORD);
});

it('validates new users', function () {
    $admin = umUser(Role::Admin);
    umUser(Role::Manager, ['email' => 'dipakai@stockpilot.test']);

    $cases = [
        ['email', umPayload(['email' => 'dipakai@stockpilot.test'])],
        ['email', umPayload(['email' => 'bukan-email'])],
        ['role', umPayload(['role' => 'superuser'])],
        ['password', umPayload(['password' => 'pendek'])],
        ['password', umPayload(['password' => ''])],
        ['warehouse_ids.0', umPayload(['warehouse_ids' => [999999]])],
        ['name', umPayload(['name' => ''])],
    ];

    foreach ($cases as [$field, $payload]) {
        $this->actingAs($admin)->post('/users', $payload)->assertSessionHasErrors($field);
    }

    expect(User::count())->toBe(2);
});

it('updates role, warehouses and password and audits only the changes', function () {
    $admin = umUser(Role::Admin);
    $target = umUser(Role::WarehouseStaff);
    $a = Warehouse::create(['code' => 'GD-A', 'name' => 'A']);
    $oldHash = $target->password;

    $this->actingAs($admin)->put("/users/{$target->id}", umPayload([
        'name' => $target->name,
        'email' => $target->email,
        'role' => 'manager',
        'password' => 'N3w-Passw0rd-Rahasia!',
        'warehouse_ids' => [$a->id],
    ]))->assertRedirect();

    $fresh = $target->fresh();

    expect($fresh->role)->toBe(Role::Manager)
        ->and($fresh->password)->not->toBe($oldHash)
        ->and(Hash::check('N3w-Passw0rd-Rahasia!', $fresh->password))->toBeTrue()
        ->and($fresh->warehouses->pluck('id')->all())->toBe([$a->id]);

    $log = AuditLog::where('action', 'user.updated')->first();

    expect($log->metadata['role'])->toBe(['warehouse_staff', 'manager'])
        ->and($log->metadata['warehouses'])->toEqual(['from' => 'none', 'to' => [$a->id]])
        ->and($log->metadata['password_reset'])->toBeTrue()
        ->and(json_encode($log->metadata))->not->toContain('Rahasia');

    // Tanpa perubahan apa pun tidak ada entri audit baru.
    $before = AuditLog::count();
    $this->actingAs($admin)->put("/users/{$target->id}", umPayload([
        'name' => $fresh->name,
        'email' => $fresh->email,
        'role' => 'manager',
        'password' => '',
        'warehouse_ids' => [$a->id],
    ]))->assertRedirect();

    expect(AuditLog::count())->toBe($before);
});

it('does not let an admin demote or deactivate themselves', function () {
    $admin = umUser(Role::Admin);
    $same = ['name' => $admin->name, 'email' => $admin->email, 'password' => ''];

    $this->actingAs($admin)
        ->put("/users/{$admin->id}", umPayload([...$same, 'role' => 'manager']))
        ->assertSessionHas('error');

    $this->actingAs($admin)
        ->put("/users/{$admin->id}", umPayload([...$same, 'role' => 'admin', 'is_active' => false]))
        ->assertSessionHas('error');

    expect($admin->fresh()->role)->toBe(Role::Admin)
        ->and((bool) $admin->fresh()->is_active)->toBeTrue();

    // Mengubah nama sendiri tetap boleh.
    $this->actingAs($admin)
        ->put("/users/{$admin->id}", umPayload([...$same, 'name' => 'Nama Baru', 'role' => 'admin']))
        ->assertSessionHas('success');

    expect($admin->fresh()->name)->toBe('Nama Baru');
});

it('can deactivate another admin only while another active admin remains', function () {
    $one = umUser(Role::Admin);
    $two = umUser(Role::Admin);

    $this->actingAs($one)->put("/users/{$two->id}", umPayload([
        'name' => $two->name, 'email' => $two->email, 'role' => 'admin', 'is_active' => false, 'password' => '',
    ]))->assertSessionHas('success');

    expect((bool) $two->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::where('action', 'user.updated')->first()->metadata['is_active'])->toBe([true, false]);
});

it('logs out and blocks an inactive account', function () {
    $inactive = umUser(Role::WarehouseStaff, ['is_active' => false]);

    $this->actingAs($inactive)->get('/dashboard')->assertRedirect('/login');

    $this->assertGuest();
});

it('does not let a user change their own role through the profile form', function () {
    $user = umUser(Role::WarehouseStaff);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Nama Baru',
        'email' => $user->email,
        'role' => 'admin',
        'is_active' => false,
    ]);

    expect($user->fresh()->role)->toBe(Role::WarehouseStaff)
        ->and((bool) $user->fresh()->is_active)->toBeTrue();
});

it('lists and filters users for the admin', function () {
    $admin = umUser(Role::Admin);
    umUser(Role::Manager, ['name' => 'Budi Manager']);
    umUser(Role::WarehouseStaff, ['name' => 'Sari Staff']);

    $this->actingAs($admin)->get('/users')->assertInertia(fn (Assert $page) => $page
        ->component('users/index')
        ->has('users.data', 3)
        ->has('roles', 3));

    $this->actingAs($admin)->get('/users?search=Budi')
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.role', 'manager'));

    $this->actingAs($admin)->get('/users?role=warehouse_staff')
        ->assertInertia(fn (Assert $page) => $page->has('users.data', 1)->where('users.data.0.name', 'Sari Staff'));
});
