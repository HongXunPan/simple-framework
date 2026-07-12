<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Execution;

final readonly class ListenerExecutionResult
{
    /** @param class-string $listenerClass */
    public function __construct(
        public string $listenerClass,
        public int $order,
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
            'elapsed_ms' => $this->elapsedMs,
            'succeeded' => $this->succeeded,
            'error_class' => $this->errorClass,
            'error_message' => $this->errorMessage,
        ];
    }
}
