<?php

namespace HongXunPan\Framework\Core;

use Closure;
use HongXunPan\Framework\Response\ErrorHandler;
use Illuminate\Container\Container;
use Throwable;

class Application extends Container
{
    use PathTrait;
    public function __construct($basePath = '')
    {
        if (!$basePath) {
            $basePath = dirname(__DIR__, 5);
        }
        $this->setPath('base', $basePath);
        self::getInstance();
    }

    public function run(Closure $closure): void
    {
        try {
            $closure($this);
        } catch (Throwable $throwable) {
            ErrorHandler::handle($throwable);
        }
    }
}
