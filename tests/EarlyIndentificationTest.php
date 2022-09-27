<?php

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Database\Models;

test('early identification works for domain identification', function (){
    config(['tenancy.tenant_model' => DomainTenant::class]);

    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $kernel->pushMiddleware(InitializeTenancyByDomain::class);

    Route::get('/foo', function () {
        return tenant()->getTenantKey();
    })->name('foo');

    $tenant = DomainTenant::create();

    $tenant->domains()->create([
        'domain' => 'localhost.test',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('/foo')
        ->assertOk()
        ->assertSee(tenant()->getTenantKey());

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('early identification works for subdomain identification');

test('early identification works for domain or subdomain identification');

test('early identification works for request path identification', function (){
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $kernel->pushMiddleware(InitializeTenancyByPath::class);

    Route::group([
        'prefix' => '/{tenant}',
    ], function () {
        Route::get('/foo', function () {
            return tenant()->getTenantKey();
        })->name('foo');
    });

    Tenant::create([
        'id' => 'acme',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('/acme/foo')
        ->assertOk()
        ->assertSee(tenant()->getTenantKey());

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('early identification works for request data identification using request header parameter', function (){
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $kernel->pushMiddleware(InitializeTenancyByRequestData::class);

    Route::get('/foo', function () {
        return tenant()->getTenantKey();
    })->name('foo');

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('/foo',  [
        'X-Tenant' => $tenant->id,
    ])->assertOk()
        ->assertSee(tenant()->getTenantKey());

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

test('early identification works for request data identification using request query parameter', function (){
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $kernel->pushMiddleware(InitializeTenancyByRequestData::class);

    Route::get('/foo', function () {
        return tenant()->getTenantKey();
    })->name('foo');

    $tenant = Tenant::create([
        'id' => 'acme',
    ]);

    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('/foo?tenant=' . $tenant->id)
        ->assertOk()
        ->assertSee(tenant()->getTenantKey());

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
});

class DomainTenant extends Models\Tenant
{
    use HasDomains;
}