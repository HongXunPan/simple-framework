<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Exceptions;

use Throwable;

final readonly class ErrorLogExceptionReporter implements ExceptionReporter
{
    public function report(Throwable $throwable): void
    {
        error_log(sprintf(
            '[simple-framework:report] %s: %s at %s:%d',
            $throwable::class,
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine(),
        ));
    }
}
