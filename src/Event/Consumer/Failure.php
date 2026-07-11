<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Consumer;

use DateTimeImmutable;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Worker\ListenerExecutionResult;
use Throwable;

final readonly class Failure
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param class-string<Event>|null $eventClass
     * @param list<ListenerExecutionResult> $listeners
     * @param class-string<Throwable>|null $errorClass
     */
    public function __construct(
        public string $messageId,
        public ?string $eventId,
        public ?string $eventClass,
        public ?int $eventVersion,
        public array $listeners,
        public ?string $errorClass,
        public ?string $errorMessage,
        public DateTimeImmutable $failedAt,
    ) {
    }

    /**
     * @return array{
     *     message_id: string,
     *     event_id: string|null,
     *     event_class: string|null,
     *     event_version: int|null,
     *     listener_total: int,
     *     listeners: list<array<string, bool|int|string|null>>,
     *     error_class: string|null,
     *     error_message: string|null,
     *     failed_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'event_id' => $this->eventId,
            'event_class' => $this->eventClass,
            'event_version' => $this->eventVersion,
            'listener_total' => count($this->listeners),
            'listeners' => array_map(
                static fn (ListenerExecutionResult $listener): array => $listener->toArray(),
                $this->listeners,
            ),
            'error_class' => $this->errorClass,
            'error_message' => $this->errorMessage,
            'failed_at' => $this->failedAt->format(self::DATE_FORMAT),
        ];
    }
}
