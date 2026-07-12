<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Serialization;

use HongXunPan\Framework\Event\Message\EventMessage;

interface Serializer
{
    public function serialize(EventMessage $message): string;

    public function deserialize(string $payload): EventMessage;
}
