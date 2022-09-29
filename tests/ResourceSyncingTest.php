<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;
use Stancl\Tenancy\Database\Models\TenantPivot;
use Stancl\Tenancy\Database\DatabaseConfig;
use Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Exceptions\ModelNotSyncMasterException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Listeners\UpdateSyncedResource;
use Stancl\Tenancy\Tests\Etc\Tenant;

beforeEach(function () {
    config(['tenancy.bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
    ]]);

    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());

    DatabaseConfig::generateDatabaseNamesUsing(function () {
        return 'db' . Str::random(16);
    });

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    UpdateSyncedResource::$shouldQueue = false; // Global state cleanup
    Event::listen(SyncedResourceSaved::class, UpdateSyncedResource::class);

    // Run migrations on central connection
    pest()->artisan('migrate', [
        '--path' => [
            __DIR__ . '/Etc/synced_resource_migrations',
            __DIR__ . '/Etc/synced_resource_migrations/users',
        ],
        '--realpath' => true,
    ])->assertExitCode(0);
});

test('an event is triggered when a synced resource is changed', function () {
    Event::fake([SyncedResourceSaved::class]);

    $user = ResourceUser::create([
        'name' => 'Foo',
        'email' => 'foo@email.com',
        'password' => 'secret',
        'global_id' => 'foo',
        'role' => 'foo',
    ]);

    Event::assertDispatched(SyncedResourceSaved::class, function (SyncedResourceSaved $event) use ($user) {
        return $event->model === $user;
    });
});

test('only the synced columns are updated in the central db', function () {
    // Create user in central DB
    $user = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'superadmin', // unsynced
    ]);

    $tenant = ResourceTenant::create();
    migrateTenantsResource();

    tenancy()->initialize($tenant);

    // Create the same user in tenant DB
    $user = ResourceUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    // Update user in tenant DB
    $user->update([
        'name' => 'John Foo', // synced
        'email' => 'john@foreignhost', // synced
        'role' => 'admin', // unsynced
    ]);

    // Assert new values
    pest()->assertEquals([
        'id' => 1,
        'global_id' => 'acme',
        'name' => 'John Foo',
        'email' => 'john@foreignhost',
        'password' => 'secret',
        'role' => 'admin',
    ], $user->getAttributes());

    tenancy()->end();

    // Assert changes bubbled up
    pest()->assertEquals([
        'id' => 1,
        'global_id' => 'acme',
        'name' => 'John Foo', // synced
        'email' => 'john@foreignhost', // synced
        'password' => 'secret', // no changes
        'role' => 'superadmin', // unsynced
    ], ResourceUser::first()->getAttributes());
});

test('creating the resource in tenant database creates it in central database as a direct copy when creation attributes are not specified', function () {
    // Assert no user exists in central DB
    expect(ResourceUser::all())->toHaveCount(0);

    $tenant = ResourceTenant::create();
    migrateTenantsResource();

    tenancy()->initialize($tenant);

    // Create the user in tenant DB
    $resourceUser = ResourceUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    // Assert central user and Resource user has exact same attributes and values
    expect($resourceUser->getSyncedCreationAttributes())->toBeNull();
    expect(CentralUser::first()->toArray())->toEqual(ResourceUser::first()->toArray());
});

test('creating the resource in tenant database creates it in central database with default attribute values', function () {
    // Assert no user exists in central DB
    expect(ResourceUserWithDefaultValues::all())->toHaveCount(0);

    $tenant = ResourceTenant::create();
    migrateTenantsResource();

    tenancy()->initialize($tenant);

    // Create the user in tenant DB
    ResourceUserWithDefaultValues::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    tenancy()->end();

    // Assert model attributes are synced
    expect(CentralUser::first()->global_id)->toBe('acme');
    expect(CentralUser::first()->name)->toBe('John Doe');
    expect(CentralUser::first()->password)->toBe('secret');
    expect(CentralUser::first()->email)->toBe('john@localhost');

    // Assert the "role" attribute is unsynced and we are using the default value
    expect(CentralUser::first()->role)->toBe('admin');
});

test('creating the resource in tenant database creates it in central database with attributes names', function () {
    // Assert no user exists in central DB
    expect(ResourceUserWithAttributeNames::all())->toHaveCount(0);

    $tenant = ResourceTenant::create();
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/custom',
        '--realpath' => true,
    ])->assertExitCode(0);

    tenancy()->initialize($tenant);

    // Create the user in tenant DB
    ResourceUserWithAttributeNames::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
        'code' => 'bar' // extra column which does not exist in central users table
    ]);

    tenancy()->end();

    // Assert central user was created without `code` property
    expect(CentralUser::first()->global_id)->toBe('acme');
    expect(CentralUser::first()->code)->toBeNull();
});

test('creating the resource in tenant database creates it in central database with a mix of attributes names and default values', function () {
    // Assert no user exists in central DB
    expect(ResourceUserWithAttributeNamesAndDefaultValues::all())->toHaveCount(0);

    $tenant = ResourceTenant::create();
    pest()->artisan('tenants:migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/custom',
        '--realpath' => true,
    ])->assertExitCode(0);

    tenancy()->initialize($tenant);

    // Create the user in tenant DB
    ResourceUserWithAttributeNamesAndDefaultValues::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // this will not be synced because we are providing default value
        'code' => 'bar' // extra column which does not exist in central users table
    ]);

    tenancy()->end();

    // Assert central user was created without `code` property
    expect(CentralUser::first()->global_id)->toBe('acme');
    expect(CentralUser::first()->name)->toBe('John Doe');
    expect(CentralUser::first()->email)->toBe('john@localhost');
    expect(CentralUser::first()->password)->toBe('secret');
    expect(CentralUser::first()->code)->toBeNull();
    expect(CentralUser::first()->role)->toBe('admin'); // unsynced so it should be default value
});

test('creating the resource in central database creates it in tenant database as direct copy when creation attributes are not specified', function () {
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $tenant = ResourceTenant::create([
        'id' => 't1',
    ]);
    migrateTenantsResource();

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach('t1');

    $centralUser = CentralUser::first();
    expect($centralUser->getSyncedCreationAttributes())->toBeNull();
    $tenant->run(function () use ($centralUser) {
        expect(ResourceUser::all())->toHaveCount(1);
        expect(ResourceUser::first()->toArray())->toEqual($centralUser->toArray());
    });
});

test('creating the resource in central database creates it in tenant database with default attributes values', function () {
    $centralUser = CentralUserWithDefaultValues::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $tenant = ResourceTenant::create([
        'id' => 't1',
    ]);
    migrateTenantsResource();

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach('t1');

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(1);

        // Assert model attributes are synced
        expect(ResourceUser::first()->global_id)->toBe('acme');
        expect(ResourceUser::first()->name)->toBe('John Doe');
        expect(ResourceUser::first()->password)->toBe('secret');
        expect(ResourceUser::first()->email)->toBe('john@localhost');

        // Assert the "role" attribute is unsynced and we are using the default value
        expect(ResourceUser::first()->role)->toBe('admin');
    });
});

test('creating the resource in central database creates it in tenant database with attributes names', function () {
    // migrate extra column "foo" in central DB
    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/users_extra',
        '--realpath' => true,
    ])->assertExitCode(0);

    $centralUser = CentralUserWithAttributeNames::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
        'foo' => 'bar', // foo does not exist in resource model
    ]);

    $tenant = ResourceTenant::create([
        'id' => 't1',
    ]);
    migrateTenantsResource();

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach('t1');

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(1);
        expect(ResourceUser::first()->global_id)->toBe('acme');
        expect(ResourceUser::first()->foo)->toBeNull(); // assert foo is not copied from the central to tenant model
    });
});

test('creating the resource in central database creates it in tenant database with a mix of attributes names and default values', function () {
    $centralUser = CentralUserWithAttributeNamesAndDefaultValues::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // this will not be synced because we are providing default value
    ]);

    $tenant = ResourceTenant::create([
        'id' => 't1',
    ]);
    migrateTenantsResource();

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach('t1');

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(1);
        expect(ResourceUser::first()->global_id)->toBe('acme');
        expect(CentralUser::first()->name)->toBe('John Doe');
        expect(CentralUser::first()->email)->toBe('john@localhost');
        expect(CentralUser::first()->password)->toBe('secret');
        expect(ResourceUser::first()->role)->toBe('admin'); // default value
    });
});

test('sync resources work when the central model creation method returns attribute names and the resource model creation method returns default values ', function (){
    // migrate central_users table and tenant_central_users pivot table
    pest()->artisan('migrate', [
        '--path' => __DIR__ . '/Etc/synced_resource_migrations/custom/central',
        '--realpath' => true,
    ])->assertExitCode(0);

    $tenant1 = ResourceTenantWithCustomPivot::create(['id' => 't1']);

    // migrate resource_users tenant table
    migrateTenantsResource(__DIR__ . '/Etc/synced_resource_migrations/custom/tenant');

    $centralUser = CentralUserWithExtraAttributes::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'admin',
        'code' => 'foo',
    ]);

    $tenant1->run(function () {
        expect(ResourceUserWithNoExtraAttributes::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach('t1');

    $tenant1->run(function () {
        $resourceUserWithNoExtraAttributes = ResourceUserWithNoExtraAttributes::first();
        expect(ResourceUserWithNoExtraAttributes::all())->toHaveCount(1);
        expect($resourceUserWithNoExtraAttributes->global_id)->toBe('acme');

        // role and code does not exist in resource_users table
        expect($resourceUserWithNoExtraAttributes->role)->toBeNull();
        expect($resourceUserWithNoExtraAttributes->code)->toBeNull();
    });

    $tenant2 = ResourceTenantWithCustomPivot::create();
    // migrate resource_users tenant table
    migrateTenantsResource(__DIR__ . '/Etc/synced_resource_migrations/custom/tenant');
    tenancy()->initialize($tenant2);

    // Create the user in tenant DB
    ResourceUserWithNoExtraAttributes::create([
        'global_id' => 'acmey',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
    ]);

    tenancy()->end();

    $centralUserWithExtraAttributes = CentralUserWithExtraAttributes::latest('id')->first(); // get the last user because first one already created
    expect($centralUserWithExtraAttributes->global_id)->toBe('acmey');

    // CentralUserWithExtraAttributes are providing these default value
    expect($centralUserWithExtraAttributes->password)->toBe('secret');
    expect($centralUserWithExtraAttributes->code)->toBe('foo');
    expect($centralUserWithExtraAttributes->role)->toBe('admin');
});

test('creating the resource in tenant database creates it in central database and creates the mapping', function () {
    creatingResourceInTenantDatabaseCreatesAndMapInCentralDatabase();
});

test('trying to update synced resources from central context using tenant models results in an exception', function () {
    creatingResourceInTenantDatabaseCreatesAndMapInCentralDatabase();

    tenancy()->end();
    expect(tenancy()->initialized)->toBeFalse();

    pest()->expectException(ModelNotSyncMasterException::class);
    ResourceUser::first()->update(['role' => 'foobar']);
});

test('attaching a tenant to the central resource triggers a pull from the tenant db', function () {
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $tenant = ResourceTenant::create([
        'id' => 't1',
    ]);
    migrateTenantsResource();

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    $centralUser->tenants()->attach('t1');

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(1);
    });
});

test('attaching users to tenants does not do anything', function () {
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $tenant = ResourceTenant::create([
        'id' => 't1',
    ]);
    migrateTenantsResource();

    $tenant->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    // The child model is inaccessible in the Pivot Model, so we can't fire any events.
    $tenant->users()->attach($centralUser);

    $tenant->run(function () {
        // Still zero
        expect(ResourceUser::all())->toHaveCount(0);
    });
});

test('resources are synced only to workspaces that have the resource', function () {
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);

    $t2 = ResourceTenant::create([
        'id' => 't2',
    ]);

    $t3 = ResourceTenant::create([
        'id' => 't3',
    ]);
    migrateTenantsResource();

    $centralUser->tenants()->attach('t1');
    $centralUser->tenants()->attach('t2');
    // t3 is not attached

    $t1->run(function () {
        // assert user exists
        expect(ResourceUser::all())->toHaveCount(1);
    });

    $t2->run(function () {
        // assert user exists
        expect(ResourceUser::all())->toHaveCount(1);
    });

    $t3->run(function () {
        // assert user does NOT exist
        expect(ResourceUser::all())->toHaveCount(0);
    });
});

test('when a resource exists in other tenant dbs but is created in a tenant db the synced columns are updated in the other dbs', function () {
    // create shared resource
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);
    $t2 = ResourceTenant::create([
        'id' => 't2',
    ]);
    migrateTenantsResource();

    // Copy (cascade) user to t1 DB
    $centralUser->tenants()->attach('t1');

    $t2->run(function () {
        // Create user with the same global ID in t2 database
        ResourceUser::create([
            'global_id' => 'acme',
            'name' => 'John Foo', // changed
            'email' => 'john@foo', // changed
            'password' => 'secret',
            'role' => 'superadmin', // unsynced
        ]);
    });

    $centralUser = CentralUser::first();
    expect($centralUser->name)->toBe('John Foo'); // name changed
    expect($centralUser->email)->toBe('john@foo'); // email changed
    expect($centralUser->role)->toBe('commenter'); // role didn't change

    $t1->run(function () {
        $user = ResourceUser::first();
        expect($user->name)->toBe('John Foo'); // name changed
        expect($user->email)->toBe('john@foo'); // email changed
        expect($user->role)->toBe('commenter'); // role didn't change, i.e. is the same as from the original copy from central
    });
});

test('the synced columns are updated in other tenant dbs where the resource exists', function () {
    // create shared resource
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);
    $t2 = ResourceTenant::create([
        'id' => 't2',
    ]);
    $t3 = ResourceTenant::create([
        'id' => 't3',
    ]);
    migrateTenantsResource();

    // Copy (cascade) user to t1 DB
    $centralUser->tenants()->attach('t1');
    $centralUser->tenants()->attach('t2');
    $centralUser->tenants()->attach('t3');

    $t3->run(function () {
        ResourceUser::first()->update([
            'name' => 'John 3',
            'role' => 'employee', // unsynced
        ]);

        expect(ResourceUser::first()->role)->toBe('employee');
    });

    // Check that change was cascaded to other tenants
    $t1->run($check = function () {
        $user = ResourceUser::first();

        expect($user->name)->toBe('John 3'); // synced
        expect($user->role)->toBe('commenter'); // unsynced
    });
    $t2->run($check);

    // Check that change bubbled up to central DB
    expect(CentralUser::count())->toBe(1);
    $centralUser = CentralUser::first();
    expect($centralUser->name)->toBe('John 3'); // synced
    expect($centralUser->role)->toBe('commenter'); // unsynced
});

test('global id is generated using id generator when its not supplied', function () {
    $user = CentralUser::create([
        'name' => 'John Doe',
        'email' => 'john@doe',
        'password' => 'secret',
        'role' => 'employee',
    ]);

    pest()->assertNotNull($user->global_id);
});

test('when the resource doesnt exist in the tenant db non synced columns will cascade too', function () {
    $centralUser = CentralUser::create([
        'name' => 'John Doe',
        'email' => 'john@doe',
        'password' => 'secret',
        'role' => 'employee',
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);

    migrateTenantsResource();

    $centralUser->tenants()->attach('t1');

    $t1->run(function () {
        expect(ResourceUser::first()->role)->toBe('employee');
    });
});

test('when the resource doesnt exist in the central db non synced columns will bubble up too', function () {
    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);

    migrateTenantsResource();

    $t1->run(function () {
        ResourceUser::create([
            'name' => 'John Doe',
            'email' => 'john@doe',
            'password' => 'secret',
            'role' => 'employee',
        ]);
    });

    expect(CentralUser::first()->role)->toBe('employee');
});

test('the listener can be queued', function () {
    Queue::fake();
    UpdateSyncedResource::$shouldQueue = true;

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);

    migrateTenantsResource();

    Queue::assertNothingPushed();

    $t1->run(function () {
        ResourceUser::create([
            'name' => 'John Doe',
            'email' => 'john@doe',
            'password' => 'secret',
            'role' => 'employee',
        ]);
    });

    Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
        return $job->class === UpdateSyncedResource::class;
    });
});

test('an event is fired for all touched resources', function () {
    Event::fake([SyncedResourceChangedInForeignDatabase::class]);

    // create shared resource
    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    $t1 = ResourceTenant::create([
        'id' => 't1',
    ]);
    $t2 = ResourceTenant::create([
        'id' => 't2',
    ]);
    $t3 = ResourceTenant::create([
        'id' => 't3',
    ]);
    migrateTenantsResource();

    // Copy (cascade) user to t1 DB
    $centralUser->tenants()->attach('t1');
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant->getTenantKey() === 't1';
    });

    $centralUser->tenants()->attach('t2');
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant->getTenantKey() === 't2';
    });

    $centralUser->tenants()->attach('t3');
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant->getTenantKey() === 't3';
    });

    // Assert no event for central
    Event::assertNotDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant === null;
    });

    // Flush
    Event::fake([SyncedResourceChangedInForeignDatabase::class]);

    $t3->run(function () {
        ResourceUser::first()->update([
            'name' => 'John 3',
            'role' => 'employee', // unsynced
        ]);

        expect(ResourceUser::first()->role)->toBe('employee');
    });

    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't1';
    });
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't2';
    });

    // Assert NOT dispatched in t3
    Event::assertNotDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't3';
    });

    // Assert dispatched in central
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant === null;
    });

    // Flush
    Event::fake([SyncedResourceChangedInForeignDatabase::class]);

    $centralUser->update([
        'name' => 'John Central',
    ]);

    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't1';
    });
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't2';
    });
    Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return optional($event->tenant)->getTenantKey() === 't3';
    });
    // Assert NOT dispatched in central
    Event::assertNotDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
        return $event->tenant === null;
    });
});

// todo@tests
function creatingResourceInTenantDatabaseCreatesAndMapInCentralDatabase()
{
    // Assert no user in central DB
    expect(ResourceUser::all())->toHaveCount(0);

    $tenant = ResourceTenant::create();
    migrateTenantsResource();

    tenancy()->initialize($tenant);

    // Create the same user in tenant DB
    ResourceUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter', // unsynced
    ]);

    tenancy()->end();

    // Assert user was created
    expect(CentralUser::first()->global_id)->toBe('acme');
    expect(CentralUser::first()->role)->toBe('commenter');

    // Assert mapping was created
    expect(CentralUser::first()->tenants)->toHaveCount(1);

    // Assert role change doesn't cascade
    CentralUser::first()->update(['role' => 'central superadmin']);
    tenancy()->initialize($tenant);
    expect(ResourceUser::first()->role)->toBe('commenter');
}

function migrateTenantsResource(?string $path = null)
{
    pest()->artisan('tenants:migrate', [
        '--path' => $path ?? __DIR__ . '/Etc/synced_resource_migrations/users',
        '--realpath' => true,
    ])->assertExitCode(0);
}

class ResourceTenant extends Tenant
{
    public function users()
    {
        return $this->belongsToMany(CentralUser::class, 'tenant_users', 'tenant_id', 'global_user_id', 'id', 'global_id')
            ->using(TenantPivot::class);
    }
}

class CentralUser extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];

    public $timestamps = false;

    public $table = 'users';

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(ResourceTenant::class, 'tenant_users', 'global_user_id', 'tenant_id', 'global_id')
            ->using(TenantPivot::class);
    }

    public function getTenantModelName(): string
    {
        return ResourceUser::class;
    }

    public function getGlobalIdentifierKey(): string|int
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'global_id';
    }

    public function getCentralModelName(): string
    {
        return static::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return [
            'global_id',
            'name',
            'password',
            'email',
        ];
    }
}

class ResourceUser extends Model implements Syncable
{
    use ResourceSyncing;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public function getGlobalIdentifierKey(): string|int
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'global_id';
    }

    public function getCentralModelName(): string
    {
        return CentralUser::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return [
            'global_id',
            'name',
            'password',
            'email',
        ];
    }
}

// override method in ResourceUser class to return attribute default values
class ResourceUserWithDefaultValues extends ResourceUser {
    public function getSyncedCreationAttributes(): array
    {
        // Attributes default values when creating resources from tenant to central DB
        return
            [
                'role' => 'admin', // Provide "role" default value because it is unsynced or does not exist in Resource model
            ];
    }
}

// override method in ResourceUser class to return attribute names
class ResourceUserWithAttributeNames extends ResourceUser {
    public function getSyncedCreationAttributes(): array
    {
        // Attributes used when creating resources from tenant to central DB
        // Notice here we are not adding "code" filed because it doesn't
        // exist in central model
        return
            [
                'global_id',
                'name',
                'password',
                'email',
                'role'
            ];
    }

}

// override method in ResourceUser class to return attribute names and default values
class ResourceUserWithAttributeNamesAndDefaultValues extends ResourceUser {
    public function getSyncedCreationAttributes(): array
    {
        // Sync name, email and password but provide default value for role
        return
            [
                'global_id',
                'name',
                'password',
                'email',
                'role' => 'admin' // default value
            ];
    }

}

// override method in CentralUser class to return attribute default values
class CentralUserWithDefaultValues extends CentralUser {
    public function getSyncedCreationAttributes(): array
    {
        // Attributes default values when creating resources from central to tenant model
        return
            [
                'role' => 'admin', // Provide "role" default value because it is unsynced or does not exist in Central model
            ];
    }
}

// override method in CentralUser class to return attribute names
class CentralUserWithAttributeNames extends CentralUser {
    public function getSyncedCreationAttributes(): array
    {
        // Attributes used when creating resources from central to tenant DB
        return
            [
                'global_id',
                'name',
                'password',
                'email',
                'role',
            ];
    }
}

// override method in CentralUser class to return attribute names and default values
class CentralUserWithAttributeNamesAndDefaultValues extends CentralUser {
    public function getSyncedCreationAttributes(): array
    {
        // Sync name, email and password but provide default value for role
        return
            [
                'global_id',
                'name',
                'password',
                'email',
                'role' => 'admin',
            ];
    }
}

class ResourceTenantWithCustomPivot extends Tenant
{
    public function users()
    {
        return $this->belongsToMany(CentralUserWithExtraAttributes::class, 'tenant_central_users', 'tenant_id', 'global_user_id', 'id', 'global_id')
            ->using(TenantPivot::class);
    }
}

class CentralUserWithExtraAttributes extends CentralUser {
    public $table = 'central_users';

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(ResourceTenantWithCustomPivot::class, 'tenant_central_users', 'global_user_id', 'tenant_id', 'global_id')
            ->using(TenantPivot::class);
    }

    public function getTenantModelName(): string
    {
        return ResourceUserWithNoExtraAttributes::class;
    }

    public function getSyncedCreationAttributes(): array
    {
        return [
            'global_id',
            'name',
            'password',
            'email',
        ];
    }
}

class ResourceUserWithNoExtraAttributes extends ResourceUser {
    protected $table = 'resource_users';

    public function getCentralModelName(): string
    {
        return CentralUserWithExtraAttributes::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return [
            'global_id',
            'name',
            'email',
        ];
    }

    public function getSyncedCreationAttributes(): array
    {
        return [
            'global_id',
            'name',
            'email',
            // Provide default values
            'password' => 'secret',
            'role' => 'admin',
            'code' => 'foo',
        ];
    }
}
