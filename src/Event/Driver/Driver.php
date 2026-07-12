<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Driver;

use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Message\EventMessage;

interface Driver
{
    /** @param array<mixed> $config */
    public static function validateConfig(array $config): void;

    /** @return class-string<Consumer> */
    public static function consumerClass(): string;

    public function publish(EventMessage $message): void;
}
