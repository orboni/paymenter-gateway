<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Orboni\Orboni;

// Registered by the extension's boot(), outside the web group: no session, no CSRF.

// Orboni's server, not a signed-in user: the signature is the check.
Route::post('/extensions/gateways/orboni/webhook', [Orboni::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.orboni.webhook');

// The buyer back from the payment page: it reads the session from Orboni, nothing else.
Route::get('/extensions/gateways/orboni/return/{invoice}', [Orboni::class, 'back'])
    ->middleware('throttle:30,1')
    ->whereNumber('invoice')
    ->name('extensions.gateways.orboni.return');
