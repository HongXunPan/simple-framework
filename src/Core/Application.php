<?php

namespace HongXunPan\Framework\Core;

use Closure;
use HongXunPan\Framework\Response\ErrorHandler;
use Illuminate\Container\Container;
use Throwable;

class Application extends Container
{
    public function run(Closure $closure): void
    {
        try {
            $closure($this);
        } catch (Throwable $throwable) {
            ErrorHandler::handle($throwable);
        }
    }
}
