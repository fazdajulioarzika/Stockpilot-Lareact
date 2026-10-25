<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function epUser(Role $role): User
{
    $user = User::factory()->create();
    $user->forceFill(['role' => $role])->save();

    return $user->fresh();
}

it('shows the friendly error page when access is forbidden', function () {
    $this->actingAs(epUser(Role::WarehouseStaff))->get('/users')
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 403));
});

it('shows the friendly error page when a record does not exist', function () {
    $this->actingAs(epUser(Role::Admin))->get('/stocktakes/999999')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('error')->where('status', 404));
});

it('keeps the default behaviour for guests', function () {
    $this->get('/users')->assertRedirect('/login');
});
