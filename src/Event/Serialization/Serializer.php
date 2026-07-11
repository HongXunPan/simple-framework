<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Serialization;

use HongXunPan\Framework\Event\Dispatch\Envelope;

interface Serializer
{
    public function serialize(Envelope $envelope): string;

    public function deserialize(string $payload): Envelope;

    /** @param class-string $eventClass */
    public function assertSupports(string $eventClass): void;
}
