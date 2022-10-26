<?php

declare(strict_types=1);


// todo@1 come up with a better name
// Purpose of this file to test `central` and `runForAll` methods with session driver set to database

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Tests\Etc\Tenant;

test('central function works when using database session driver', function (){
    config(['session.driver' => 'database']);

    $tenant = Tenant::create();

    $tenant->domains()->create(['domain' => 'foo.localhost']);

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


    Route::middleware(['web', InitializeTenancyByDomain::class])->group(function () {
        Route::get('/central', function () {
            session(['message' => 'my message']);

             tenancy()->central(function (){
                return 'central results';
            });

             return session('message');

        });
    });

    pest()->get('http://foo.localhost/central')
        ->assertOk()
        ->assertSee('my message');

})->group('current');
