<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Events\InitializingTenancy;
use Stancl\Tenancy\Exceptions\RouteIsMissingTenantParameterException;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByPath extends IdentificationMiddleware
{
    /** @var callable|null */
    public static $onFail;

    /** @var Tenancy */
    protected $tenancy;

    /** @var PathTenantResolver */
    protected $resolver;

    public function __construct(Tenancy $tenancy, PathTenantResolver $resolver)
    {
        $this->tenancy = $tenancy;
        $this->resolver = $resolver;
    }

    public function handle(Request $request, Closure $next)
    {
        $route = $this->getRoute($request);

        // Only initialize tenancy if tenant is the first parameter
        // We don't want to initialize tenancy if the tenant is
        // simply injected into some route controller action.
        if ($route->parameterNames()[0] === PathTenantResolver::$tenantParameterName) {
            // Set tenant as a default parameter for the URLs in the current request
            Event::listen(InitializingTenancy::class, function (InitializingTenancy $event) {
                URL::defaults([PathTenantResolver::$tenantParameterName => $event->tenancy->tenant->getTenantKey()]);
            });

            return $this->initializeTenancy(
                $request,
                $next,
                $route
            );
        } else {
            throw new RouteIsMissingTenantParameterException;
        }

        return $next($request);
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
