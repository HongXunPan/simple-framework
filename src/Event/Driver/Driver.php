<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Driver;

use HongXunPan\Framework\Event\Dispatch\Envelope;

interface Driver
{
    public function publish(Envelope $envelope): void;
}
