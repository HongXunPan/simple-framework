<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Consumer;

final readonly class Message
{
    public function __construct(
        public string $id,
        public string $body,
    ) {
    }
}
