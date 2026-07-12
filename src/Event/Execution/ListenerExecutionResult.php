<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Execution;

use DateTimeImmutable;

final readonly class ListenerExecutionResult
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** @param class-string $listenerClass */
    public function __construct(
        public string $listenerClass,
        public int $order,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
        public int $elapsedMs,
        public bool $succeeded,
        public ?string $errorClass = null,
        public ?string $errorMessage = null,
    ) {
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'listener_class' => $this->listenerClass,
            'listener_order' => $this->order,
            'started_at' => $this->startedAt->format(self::DATE_FORMAT),
            'finished_at' => $this->finishedAt->format(self::DATE_FORMAT),
            'elapsed_ms' => $this->elapsedMs,
            'status' => $this->succeeded ? 'succeeded' : 'failed',
            'succeeded' => $this->succeeded,
            'error_class' => $this->errorClass,
            'error_message' => $this->errorMessage,
        ];
    }
}
