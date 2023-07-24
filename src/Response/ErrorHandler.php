<?php

namespace HongXunPan\Framework\Response;

use Throwable;

class ErrorHandler
{
    public static function handle(Throwable $throwable)
    {
        dd($throwable);
    }
}