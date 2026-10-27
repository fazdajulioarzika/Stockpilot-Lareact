<?php

use App\Models\User;

test('sends guests to login and signed-in users to the dashboard', function () {
    $this->get('/')->assertRedirect('/dashboard');

    $this->get('/dashboard')->assertRedirect('/login');

    $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/dashboard');
});
