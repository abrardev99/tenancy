<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\TenantDatabaseManagers;

use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class PostgreSQLDatabaseManager extends TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->statement("CREATE DATABASE \"{$tenant->database()->getName()}\" WITH TEMPLATE=template0");
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->statement("DROP DATABASE \"{$tenant->database()->getName()}\"");
    }

    public function databaseExists(string $name): bool
    {
        //dd($this->database('pg'));
        //$this->setConnection('tenant_host_connection');
        $db = DB::connection('tenant_host_connection');
        dd($db, config('database.connections.tenant_host_connection'));
        return (bool) $this->database('pg')->select("SELECT datname FROM pg_database WHERE datname = '$name'");
    }
}
