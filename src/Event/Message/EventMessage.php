<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Message;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Listener\ShouldQueue;

final readonly class EventMessage
{
    public const int VERSION = 2;

    /**
     * @param list<class-string<ShouldQueue>> $listeners
     */
    public function __construct(
        public string $eventId,
        public DateTimeImmutable $createdAt,
        public Event $event,
        public array $listeners,
        public ?string $traceId = null,
        public int $messageVersion = self::VERSION,
    ) {
    }
}
