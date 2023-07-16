<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\InitializingTenancy;
use Stancl\Tenancy\Exceptions\RouteIsMissingTenantParameterException;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByPath extends IdentificationMiddleware
{
    public static ?Closure $onFail = null;

    public function __construct(
        protected Tenancy $tenancy,
        protected PathTenantResolver $resolver,
    ) {
    }

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        $route = $this->getRoute($request);

        // Only initialize tenancy if tenant is the first parameter
        // We don't want to initialize tenancy if the tenant is
        // simply injected into some route controller action.
        if ($route->parameterNames()[0] === PathTenantResolver::tenantParameterName()) {
            $this->setDefaultTenantForRouteParametersWhenTenancyIsInitialized();

            return $this->initializeTenancy(
                $request,
                $next,
                $route
            );
        } else {
            throw new RouteIsMissingTenantParameterException;
        }
    }

    protected function setDefaultTenantForRouteParametersWhenTenancyIsInitialized(): void
    {
        Event::listen(InitializingTenancy::class, function (InitializingTenancy $event) {
            /** @var Tenant $tenant */
            $tenant = $event->tenancy->tenant;

            URL::defaults([
                PathTenantResolver::tenantParameterName() => $tenant->getTenantKey(),
            ]);
        });
    }

    protected function getRoute(Request $request): Route
    {
        /** @var Route $route */
        $route = $request->route();

        if (! $route) {
            $route = new Route($request->method(), $request->getUri(), []);
            /**
             * getPathInfo() returns the path except the root domain.
             * The path info always starts with a /.
             * We always fetch the first parameter because tenant parameter will always be first.
             *
             *  http://localhost.test/acme ==> $request->getPathInfo() ==> /acme ==> explode('/', $request->getPathInfo())[1] ==> acme
             *  http://localhost.test/acme/foo ==> $request->getPathInfo() ==> /acme/foo ==> explode('/', $request->getPathInfo())[1] ==> acme
             */
            $route->parameters[PathTenantResolver::$tenantParameterName] = explode('/', $request->getPathInfo())[1];
            $route->parameterNames[] = PathTenantResolver::$tenantParameterName;
        }

        return $route;
    }
}
