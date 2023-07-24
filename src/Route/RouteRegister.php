<?php

namespace HongXunPan\Framework\Route;

/**
 * @method RouteRegister name(string $routeName)
 * @method RouteRegister domain(string|array $domain)
 *
 * Created by PhpStorm At 2022/10/18 02:13.
 * Author: HongXunPan
 * Email: me@kangxuanpeng.com
 */
class RouteRegister
{
    private RouteOne $route;

    public function __construct(string $method, string $uri, mixed $action, $middlewares)
    {
        $uri = RouteOne::formatSlash($uri);
        $route = [
            'uri' => $uri,
            'method' => $method,
            'action' => $action,
            'name' => $uri,
            'middlewares' => $middlewares,
//            'domain' => null,
        ];
        $this->route = new RouteOne(...$route);
        $this->updateRoute();
    }

    private function updateRoute()
    {
        Route::setRouteList($this->route->uri, $this->route->toArray());
    }

    public function __call(string $name, array $arguments)
    {
        $setAttributeFunction = ['name', 'domain'];
        if (in_array($name, $setAttributeFunction)) {
            $this->route->$name = $arguments[0];
            $this->updateRoute();
            return $this;
        }
        return null;
    }

//    public function name(string $routeName): static
//    {
//        $this->route->name = $routeName;
//        $this->updateRoute();
//        return $this;
//    }
//
//    public function domain(string|array $domain): static
//    {
//        $this->route->domain = $domain;
//        $this->updateRoute();
//        return $this;
//    }
//
    public function middlewares(array $middlewares): static
    {
        $this->route->middlewares = array_merge($this->route->middlewares, $middlewares);
        $this->updateRoute();
        return $this;
    }

    public function prefix(string $prefix): RouteRegister
    {
        Route::removeRoute($this->route->uri);
        $newUri = RouteOne::formatSlash($prefix) . RouteOne::formatSlash($this->route->uri);
        return new self($this->route->method, $newUri, $this->route->action, $this->route->middlewares);
    }
}