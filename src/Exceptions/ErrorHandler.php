<?php

namespace HongXunPan\Framework\Exceptions;

use Throwable;

class ErrorHandler
{
    public static function handle(Throwable $throwable): void
    {
        dd($throwable);
    }
}