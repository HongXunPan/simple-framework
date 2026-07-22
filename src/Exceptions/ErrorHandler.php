<?php

namespace HongXunPan\Framework\Exceptions;

use Throwable;

class ErrorHandler
{
    public static function handle(Throwable $throwable): void
    {
        report($throwable);

        try {
            app(ExceptionRenderer::class)->render($throwable);
        } catch (Throwable $rendererFailure) {
            error_log(sprintf(
                '[simple-framework:render] renderer failure: %s; original: %s',
                $rendererFailure::class,
                $throwable::class,
            ));
            (new SafeExceptionRenderer())->render($throwable);
        }
    }
}
