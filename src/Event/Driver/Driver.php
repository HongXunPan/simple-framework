<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Driver;

use HongXunPan\Framework\Event\Consumer\Consumer;
use HongXunPan\Framework\Event\Dispatch\Envelope;

interface Driver
{
    /** @return class-string<Consumer> */
    public static function consumer(): string;

    public function publish(Envelope $envelope): void;
}
