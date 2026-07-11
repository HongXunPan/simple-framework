<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Dispatch;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Listener\ShouldQueue;

final readonly class Envelope
{
    /**
     * @param list<class-string<ShouldQueue>> $listeners
     */
    public function __construct(
        public string $eventId,
        public DateTimeImmutable $occurredAt,
        public Event $event,
        public array $listeners,
        public ?string $traceId = null,
    ) {
    }
}
