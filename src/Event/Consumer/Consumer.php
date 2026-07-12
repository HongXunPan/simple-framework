<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Consumer;

use HongXunPan\Framework\Event\Execution\Failure;

interface Consumer
{
    /** @return iterable<ReceivedMessage> */
    public function receive(): iterable;

    public function acknowledge(ReceivedMessage $message): void;

    public function fail(ReceivedMessage $message, Failure $failure): void;
}
