<?php

namespace HongXunPan\Framework\Core;

abstract class SingletonAbstract
{
    protected static mixed $instance;

    protected function __construct()
    {
        //
    }

    final public static function getInstance(): static
    {
        $class = get_called_class();
        if (!isset(static::$instance[$class])) {
            static::$instance[$class] = new $class();
        }
        return static::$instance[$class];
    }

    final protected function __clone()
    {
        //
    }
}