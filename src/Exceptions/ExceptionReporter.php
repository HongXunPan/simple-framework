<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Exceptions;

use Throwable;

interface ExceptionReporter
{
    /**
     * 实现方应自行处理上报失败，不得抛出新的异常。
     */
    public function report(Throwable $throwable): void;
}
