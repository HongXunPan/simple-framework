<?php

namespace HongXunPan\Framework\Route;

class RouteOne
{
    public function __construct(
        public string       $method,
        public string       $uri,
        public mixed        $action,
        public string       $name,
        public array        $middlewares = [],
        public string|array $domain = ''
    )
    {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function validate(
        string $method,
        string $uri,
        string $domain,
    ): static
    {
        $uri = self::formatSlash($uri);
        if ($uri != $this->uri) {
            throw new RouteException('uri error', 404);
        }
        $method = strtolower($method);
        if ($this->method !== 'any' && $method != $this->method) {
            throw new RouteException('method not allow', 405);
        }
        return $this;
    }

    public static function formatSlash(string $str): string
    {
        if (!str_starts_with($str, '/')) {
            $str = '/' . $str;
        }
        if (strlen($str) > 1) {
            $str = rtrim($str, '/');
        }
        return $str;
    }

    public function run()
    {
        $handler = function () {
            if (is_callable($this->action)) {
                return call_user_func($this->action);
            }
            //make
            return call_user_func([new $this->action[0], $this->action[1]]);
        };
//        dump(array_reverse($this->middlewares), $this->middlewares);
        foreach (array_reverse($this->middlewares) as $middleware) {
            $handler = function () use ($handler, $middleware) {
                if (is_callable($middleware)) {
                    return call_user_func($middleware, $handler);
                }
                if (class_exists($middleware)) {
                    //make
                    $class = new $middleware();
                    if (!$class instanceof Middleware) {
                        throw new RouteException("middleware error: $middleware");
                    }
                }
                return call_user_func([new $middleware, 'handle'], $handler);
            };
        }
//        dump($handler);
        return call_user_func($handler);
    }

}