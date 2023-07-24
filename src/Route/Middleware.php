<?php

namespace HongXunPan\Framework\Route;

use Closure;

abstract class Middleware
{
    abstract public function handle(Closure $next);
}