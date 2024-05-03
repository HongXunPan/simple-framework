<?php

namespace HongXunPan\Framework\Route;

use Closure;
use HongXunPan\Framework\Core\SingletonAbstract;
use Opis\Closure\SerializableClosure;

/**
 * @method static RouteRegister any(string $path, array|string|callable $action)
 * @method static RouteRegister get(string $path, array|string|callable $action)
 * @method static RouteRegister post(string $path, array|string|callable $action)
 * @method static void group(array|Closure $options, Closure $callback = null)
 *
 * @method static Route getInstance()
 *
 * Created by PhpStorm At 2022/10/17 06:11.
 * Author: HongXunPan
 * Email: me@kangxuanpeng.com
 */
class Route extends SingletonAbstract
{
    public array $routeList = [];

    private Group $defaultGroup;

    protected function __construct()
    {
        parent::__construct();
        $this->defaultGroup = new Group();
    }

    public static function __callStatic(string $name, array $arguments)
    {
        $instance = self::getInstance();
        return $instance->defaultGroup->$name(...$arguments);
    }

    public static function setRouteList(string $uri, array $route): void
    {
        self::getInstance()->routeList[$uri] = $route;
    }

    public static function removeRoute(string $uri): void
    {
        if (isset(self::getInstance()->routeList[$uri])) {
            unset(self::getInstance()->routeList[$uri]);
        }
    }

    public static function getRouteByName(string $name): array
    {
        $routeList = self::getInstance()->routeList;
        $uri = array_search($name, array_column($routeList, 'name', 'uri'));
        if ($uri === false) {
            throw new  RouteException("route name: $name not found");
        }
        return $routeList[$uri];
    }

    public static function run()
    {
        $request = [
            'method' => strtolower($_SERVER['REQUEST_METHOD'] ?? ''),
            'domain' => $_SERVER['SERVER_NAME'] ?? '',
            'uri' => RouteOne::formatSlash(explode('?', $_SERVER['REQUEST_URI'] ?? '')[0]),
        ];
        $route = Route::getRouteByUri($request['uri']);
        if (!$route) {
            throw new RouteException('route: ' . $request['uri'] . 'not found', 404);
        }
        return (new RouteOne(...$route))->validate(...$request)->run();
    }

    public static function getRouteByUri(string $uri)
    {
        return self::getInstance()->routeList[$uri] ?? null;
    }

    public static function loadAllRouteByFile($path): void
    {
        $files = glob($path . '/*.php');
        foreach ($files as $file) {
            require $file;
        }
    }

    public static function cache($dir, $fileName = 'route.php'): void
    {
        $routeList = self::getInstance()->routeList;

        foreach ($routeList as &$route) {
            if ($route['action'] instanceof Closure) {
                $route['action'] = new SerializableClosure($route['action']);
            }
        }
        if (!file_exists($dir)) {
            mkdir($dir);
        }
        $file = $dir . '/' . $fileName;
        file_put_contents($file, serialize($routeList));
    }

    public static function loadCache($file): void
    {
        $cache = unserialize(file_get_contents($file));
        self::getInstance()->routeList = $cache;
    }
}