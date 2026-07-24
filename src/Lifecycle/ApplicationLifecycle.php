<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Lifecycle;

interface ApplicationLifecycle
{
    public function requestHandled(RequestHandledSnapshot $snapshot): void;

    public function exceptionOccurred(ExceptionOccurredSnapshot $snapshot): void;
}
