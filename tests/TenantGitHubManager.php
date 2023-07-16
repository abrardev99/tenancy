<?php

namespace Stancl\Tenancy\Tests;

/**
 * todo@1 add description why this class added.
 * basically we are using this class to resolve from container to check if tenancy initialized and class resolved from the tenancy context
*/
class TenantGitHubManager
{
    public string $token;

    public function __construct()
    {
        $this->token = 'central token';

        if (tenant()?->getTenantKey())
        {
            $this->token = 'Tenant token: ' . tenant()->getTenantKey();
        }
    }
}