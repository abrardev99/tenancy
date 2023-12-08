<?php

declare(strict_types=1);

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Tests\Etc\CacheService;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\SpecificCacheStoreService;
use Stancl\Tenancy\Bootstrappers\PrefixCacheTenancyBootstrapper;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            PrefixCacheTenancyBootstrapper::class
        ],
        'cache.default' => $cacheDriver = 'redis',
        'cache.stores.' . $secondCacheDriver = 'redis2' => config('cache.stores.redis'),
    ]);

    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [$cacheDriver, $secondCacheDriver];
    PrefixCacheTenancyBootstrapper::$prefixGenerator = null;

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

afterEach(function () {
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [];
    PrefixCacheTenancyBootstrapper::$prefixGenerator = null;
});

test('correct cache prefix is used in all contexts', function () {
    $originalPrefix = config('cache.prefix');
    $prefixBase = config('tenancy.cache.prefix_base');
    $getDefaultPrefixForTenant = fn (Tenant $tenant) => $originalPrefix . $prefixBase . $tenant->getTenantKey();
    $bootstrapper = app(PrefixCacheTenancyBootstrapper::class);

    $expectCachePrefixToBe = function (string $prefix) {
        expect($prefix . ':') // RedisStore suffixes prefix with ':'
            ->toBe(app('cache')->getPrefix())
            ->toBe(app('cache.store')->getPrefix())
            ->toBe(cache()->getPrefix())
            ->toBe(cache()->store('redis2')->getPrefix()); // Non-default cache stores specified in $tenantCacheStores are prefixed too
    };

    $expectCachePrefixToBe($originalPrefix);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);
    cache()->set('key', 'tenantone-value');
    $tenantOnePrefix = $getDefaultPrefixForTenant($tenant1);
    $expectCachePrefixToBe($tenantOnePrefix);
    expect($bootstrapper->generatePrefix($tenant1))->toBe($tenantOnePrefix);

    tenancy()->initialize($tenant2);
    cache()->set('key', 'tenanttwo-value');
    $tenantTwoPrefix = $getDefaultPrefixForTenant($tenant2);
    $expectCachePrefixToBe($tenantTwoPrefix);
    expect($bootstrapper->generatePrefix($tenant2))->toBe($tenantTwoPrefix);

    // Prefix gets reverted to default after ending tenancy
    tenancy()->end();
    $expectCachePrefixToBe($originalPrefix);

    // Assert tenant's data is accessible using the prefix from the central context
    config(['cache.prefix' => null]); // stop prefixing cache keys in central so we can provide prefix manually
    app('cache')->forgetDriver(config('cache.default'));

    expect(cache($tenantOnePrefix . ':key'))->toBe('tenantone-value');
    expect(cache($tenantTwoPrefix . ':key'))->toBe('tenanttwo-value');
});

test('cache is persisted when reidentification is used', function () {
    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache(['foo' => 'bar']);
    expect(cache('foo'))->toBe('bar');

    tenancy()->initialize($tenant2);
    expect(cache('foo'))->toBeNull();
    tenancy()->end();

    tenancy()->initialize($tenant1);
    expect(cache('foo'))->toBe('bar');
});

test('prefixing separates the cache', function () {
    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    cache()->put('foo', 'bar');
    expect(cache()->get('foo'))->toBe('bar');

    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant2);

    expect(cache()->get('foo'))->toBeNull();

    cache()->put('foo', 'xyz');
    expect(cache()->get('foo'))->toBe('xyz');

    tenancy()->initialize($tenant1);
    expect(cache()->get('foo'))->toBe('bar');
});

test('central cache is persisted', function () {
    cache()->put('key', 'central');

    $tenant1 = Tenant::create();
    tenancy()->initialize($tenant1);

    expect(cache('key'))->toBeNull();
    cache()->put('key', 'tenant');

    expect(cache()->get('key'))->toBe('tenant');

    tenancy()->end();
    cache()->put('key2', 'central-two');

    expect(cache()->get('key'))->toBe('central');
    expect(cache()->get('key2'))->toBe('central-two');
    
    tenancy()->initialize($tenant1);
    expect(cache()->get('key'))->toBe('tenant');
    expect(cache()->get('key2'))->toBeNull();
});

test('cache base prefix is customizable', function () {
    config([
        'tenancy.cache.prefix_base' => $prefixBase = 'custom_'
    ]);

    $originalPrefix = config('cache.prefix');
    $tenant1 = Tenant::create();

    tenancy()->initialize($tenant1);

    expect($originalPrefix . $prefixBase . $tenant1->getTenantKey() . ':')
        ->toBe(cache()->getPrefix())
        ->toBe(cache()->store('redis2')->getPrefix()) // Non-default store gets prefixed correctly too
        ->toBe(app('cache')->getPrefix())
        ->toBe(app('cache.store')->getPrefix());
});

test('cache is prefixed correctly when using a repository injected in a singleton', function () {
    $this->app->singleton(CacheService::class);

    expect(cache('key'))->toBeNull();

    $this->app->make(CacheService::class)->handle();

    expect(cache('key'))->toBe('central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant2->getTenantKey());

    tenancy()->end();

    expect(cache('key'))->toBe('central-value');
});

test('specific central cache store can be used inside a service', function () {
    // Make sure 'redis' (the default store) is the only prefixed store
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = ['redis'];
    // Name of the non-default, central cache store that we'll use using cache()->store($cacheStore)
    $cacheStore = 'redis2';

    // Service uses the 'redis2' store which is central/not prefixed (not present in PrefixCacheTenancyBootstrapper::$tenantCacheStores)
    // The service's handle() method sets the value of the cache key 'key' to the current tenant key
    // Or to 'central-value' if tenancy isn't initialized
    $this->app->singleton(SpecificCacheStoreService::class, function() use ($cacheStore) {
        return new SpecificCacheStoreService($this->app->make(CacheManager::class), $cacheStore);
    });

    $this->app->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe('central-value');

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();
    tenancy()->initialize($tenant1);

    // The store isn't prefixed, so the cache isn't separated – the values persist from one context to another
    // Also assert that the value of 'key' is set correctly inside SpecificCacheStoreService according to the current context
    expect(cache()->store($cacheStore)->get('key'))->toBe('central-value');
    $this->app->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant1->getTenantKey());

    tenancy()->initialize($tenant2);

    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant1->getTenantKey());
    $this->app->make(SpecificCacheStoreService::class)->handle();
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant2->getTenantKey());

    tenancy()->end();
    // We last executed handle() in tenant2's context, so the value should persist as tenant2's id
    expect(cache()->store($cacheStore)->get('key'))->toBe($tenant2->getTenantKey());
});

test('only the stores specified in tenantCacheStores get prefixed', function () {
    // Make sure the currently used store ('redis') is the only store in $tenantCacheStores
    PrefixCacheTenancyBootstrapper::$tenantCacheStores = [$prefixedStore = 'redis'];

    $centralValue = 'central-value';
    $assertStoreIsNotPrefixed = function (string $unprefixedStore) use ($prefixedStore, $centralValue) {
        // Switch to the unprefixed store
        config(['cache.default' => $unprefixedStore]);
        expect(cache('key'))->toBe($centralValue);
        // Switch back to the prefixed store
        config(['cache.default' => $prefixedStore]);
    };

    $this->app->singleton(CacheService::class);

    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($centralValue);

    $tenant1 = Tenant::create();
    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant1);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant1->getTenantKey());

    $assertStoreIsNotPrefixed('redis2');

    tenancy()->initialize($tenant2);

    expect(cache('key'))->toBeNull();
    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($tenant2->getTenantKey());

    $assertStoreIsNotPrefixed('redis2');

    tenancy()->end();
    expect(cache('key'))->toBe($centralValue);

    $this->app->make(CacheService::class)->handle();
    expect(cache('key'))->toBe($centralValue);
});

test('non default stores get prefixed too when specified in tenantCacheStores', function () {
    // In beforeEach, we set $tenantCacheStores to ['redis', 'redis2']
    // Make 'redis' the default cache driver
    config(['cache.default' => 'redis']);

    $tenant = Tenant::create();
    $defaultPrefix = cache()->store()->getPrefix();
    $bootstrapper = app(PrefixCacheTenancyBootstrapper::class);

    // The prefix is the same for both drivers in the central context
    expect(cache()->store('redis')->getPrefix())->toBe($defaultPrefix);
    expect(cache()->store('redis2')->getPrefix())->toBe($defaultPrefix);

    tenancy()->initialize($tenant);

    // We didn't add a prefix generator for our 'redis2' driver, so we expect the prefix to be generated using the 'default' generator
    expect($bootstrapper->generatePrefix($tenant) . ':')
        ->toBe(cache()->getPrefix())
        ->toBe(cache()->store('redis2')->getPrefix()); // Non-default store

    tenancy()->end();
});

test('cache store prefix generation can be customized', function() {
    // Use custom prefix generator
    PrefixCacheTenancyBootstrapper::generatePrefixUsing($customPrefixGenerator = function (Tenant $tenant) {
        return 'redis_tenant_cache_' . $tenant->getTenantKey();
    });

    expect(PrefixCacheTenancyBootstrapper::$prefixGenerator)->toBe($customPrefixGenerator);
    expect(app(PrefixCacheTenancyBootstrapper::class)->generatePrefix($tenant = Tenant::create()))
        ->toBe($customPrefixGenerator($tenant));

    tenancy()->initialize($tenant = Tenant::create());

    // Expect the 'redis' store to use the prefix generated by the custom generator
    expect($customPrefixGenerator($tenant) . ':')
        ->toBe(cache()->getPrefix())
        ->toBe(cache()->store('redis2')->getPrefix()) // Non-default cache stores specified in $tenantCacheStores are prefixed too
        ->toBe(app('cache')->getPrefix())
        ->toBe(app('cache.store')->getPrefix());

    tenancy()->end();
});

test('stores get prefixed using the default way if no prefix generator is specified', function() {
    $originalPrefix = config('cache.prefix');
    $prefixBase = config('tenancy.cache.prefix_base');
    $tenant = Tenant::create();
    $defaultPrefix = $originalPrefix . $prefixBase . $tenant->getTenantKey();

    // Don't specify a prefix generator
    // Let the prefix get created using the default approach
    tenancy()->initialize($tenant);

    // All stores use the default way of generating the prefix when the prefix generator isn't specified
    expect($defaultPrefix . ':')
        ->toBe(app(PrefixCacheTenancyBootstrapper::class)->generatePrefix($tenant) . ':')
        ->toBe(cache()->getPrefix()) // Get prefix of the default store ('redis')
        ->toBe(cache()->store('redis2')->getPrefix());

    tenancy()->end();
});
