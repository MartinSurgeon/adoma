<?php

use App\Models\User;

it('sends visitors who are not signed in to the login page', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('sends signed-in users to the dashboard', function () {
    $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('dashboard'));
});
