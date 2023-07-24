<?php

namespace HongXunPan\Framework\Route;

use Closure;

/**
 * @method RouteRegister get(string $uri, array|string|callable $callable)
 *
 * Created by PhpStorm At 2022/10/17 06:48.
 * Author: HongXunPan
 * Email: me@kangxuanpeng.com
 */
class Group
{
    private $group;

    private array $allowMethods = [
        'get', 'post', 'put', 'patch', 'delete', 'options', 'any',
    ];

    public function __construct(array $group = [])
    {
        $this->group = new class (...$group) {
            public function __construct(
                public string $prefix = '',
                public array  $middlewares = []
            )
            {
                if (!empty($this->prefix)) {
                    $this->prefix = RouteOne::formatSlash($this->prefix);
                }
            }

            public function toArray(): array
            {
                return get_object_vars($this);
            }
        };
    }

    public function __call(string $name, array $arguments): ?RouteRegister
    {
        if (in_array($name, $this->allowMethods)) {
            return $this->addRoute($name, ...$arguments);
        }
        return null;
    }

    private function addRoute(string $method, string $uri, array|string|callable $callable): RouteRegister
    {
        $uri = $this->group->prefix . RouteOne::formatSlash($uri);
        return new RouteRegister($method, $uri, $callable, $this->group->middlewares);
    }

    public function group(array|Closure $options, Closure $callback = null): void
    {
        if ($options instanceof Closure) {
            $callback = $options;
            $options = [];
        }
        $subGroup = [];
        $subGroup['prefix'] = $this->group->prefix;
        if (!empty($options['prefix']) && is_string($options['prefix'])) {
            $subGroup['prefix'] ??= RouteOne::formatSlash($options['prefix']) . $this->group->prefix;
        }
        if (!empty($options['middlewares']) && is_array($options['middlewares'])) {
            $subGroup['middlewares'] ??= array_merge($this->group->middlewares, $options['middlewares']);
        }
//        $subGroup['prefix'] ??= $options['prefix'];
        $childGroup = new self($subGroup);
        $callback($childGroup);
    }

}