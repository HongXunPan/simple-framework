<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Driver;

use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Dispatch\EventMessage;

interface Driver
{
    /** @param array<mixed> $config */
    public static function validateConfig(array $config): void;

    /** @return class-string<Consumer> */
    public static function consumer(): string;

    public function publish(EventMessage $message): void;
}
