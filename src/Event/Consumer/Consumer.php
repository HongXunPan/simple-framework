<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Consumer;

interface Consumer
{
    /** @return iterable<Message> */
    public function receive(): iterable;

    public function acknowledge(Message $message): void;

    public function fail(Message $message, Failure $failure): void;
}
