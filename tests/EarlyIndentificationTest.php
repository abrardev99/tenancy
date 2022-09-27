<?php

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomainOrSubdomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Middleware\InitializeTenancyByRequestData;
use Stancl\Tenancy\Database\Models;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Tests\TenantGitHubManager;

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

    expect(app(TenantGitHubManager::class)->token)->toBe('central token');
    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('/acme/foo')
        ->assertOk()
        ->assertSee(tenant()->getTenantKey());

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
    expect(app(TenantGitHubManager::class)->token)->toBe('Tenant token: ' . tenant()->getTenantKey());
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

    expect(app(TenantGitHubManager::class)->token)->toBe('central token');
    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('/foo',  [
        'X-Tenant' => $tenant->id,
    ])->assertOk()
        ->assertSee(tenant()->getTenantKey());

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
    expect(app(TenantGitHubManager::class)->token)->toBe('Tenant token: ' . tenant()->getTenantKey());
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

    expect(app(TenantGitHubManager::class)->token)->toBe('central token');
    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('/foo?tenant=' . $tenant->id)
        ->assertOk()
        ->assertSee(tenant()->getTenantKey());

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe('acme');
    expect(app(TenantGitHubManager::class)->token)->toBe('Tenant token: ' . tenant()->getTenantKey());
});

test('early identification works for domain identification', function (){
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $kernel->pushMiddleware(InitializeTenancyByDomain::class);

    domainIdentificationTest();
});

test('early identification works for subdomain identification', function (){
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $kernel->pushMiddleware(InitializeTenancyBySubdomain::class);

    subdomainIdentificationTest();
});

test('early identification works for domain or subdomain identification', function (){
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $kernel->pushMiddleware(InitializeTenancyByDomainOrSubdomain::class);

    domainIdentificationTest();
    tenancy()->end();
    subdomainIdentificationTest();
});

class TenantWithDomain extends Models\Tenant
{
    use HasDomains;
}

function domainIdentificationTest(): void
{
    config(['tenancy.tenant_model' => TenantWithDomain::class]);

    Route::get('/foo', function () {
        return tenant()->getTenantKey();
    })->name('foo');

    $tenant = DomainTenant::create();

    $domain = 'foo.test';
    $tenant->domains()->create([
        'domain' => $domain,
    ]);

    expect(app(TenantGitHubManager::class)->token)->toBe('central token');
    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('http://foo.test/foo') // custom domain
    ->assertOk()
        ->assertSee(tenant()->getTenantKey());

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe($tenant->id);
    expect(app(TenantGitHubManager::class)->token)->toBe('Tenant token: ' . tenant()->getTenantKey());
}

function subdomainIdentificationTest(): void
{
    config(['tenancy.tenant_model' => TenantWithDomain::class]);

    Route::get('/foo', function () {
        return tenant()->getTenantKey();
    })->name('foo');

    $tenant = DomainTenant::create();

    $tenant->domains()->create([
        'domain' => 'foo',
    ]);

    expect(app(TenantGitHubManager::class)->token)->toBe('central token');
    expect(tenancy()->initialized)->toBeFalse();

    pest()->get('http://foo.localhost/foo')
        ->assertOk()
        ->assertSee(tenant()->getTenantKey());

    expect(tenancy()->initialized)->toBeTrue();
    expect(tenant('id'))->toBe($tenant->id);
    expect(app(TenantGitHubManager::class)->token)->toBe('Tenant token: ' . tenant()->getTenantKey());
}