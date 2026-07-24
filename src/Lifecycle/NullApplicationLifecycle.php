<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Lifecycle;

final readonly class NullApplicationLifecycle implements ApplicationLifecycle
{
    public function requestHandled(RequestHandledSnapshot $snapshot): void
    {
    }

    public function exceptionOccurred(ExceptionOccurredSnapshot $snapshot): void
    {
    }
}
