<?php

declare(strict_types=1);


// todo@1 come up with a better name
// Purpose of this file to test `central` and `runForAll` methods with session driver set to database

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Session\Middleware\StartSession;

test('central function works when using database session driver', function (){
    app(Kernel::class)->pushMiddleware(StartSession::class);
    config(['session.driver' => 'database']);

    $tenant = \Stancl\Tenancy\Tests\Etc\Tenant::create();
    tenancy()->initialize($tenant);

    // run for central
    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/Etc/session_migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    // run for tenants
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/session_migrations',
        '--realpath' => true,
    ])->assertExitCode(0);

    session(['message' => 'my message']);

    tenancy()->central(function (){
        return [];
    });

    expect(session('message'))->toBe('my message');

})->group('current');
