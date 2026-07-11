<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Worker;

final readonly class EnvelopeExecutionResult
{
    /** @param list<ListenerExecutionResult> $listeners */
    public function __construct(public array $listeners)
    {
    }

    public function succeeded(): bool
    {
        foreach ($this->listeners as $listener) {
            if (!$listener->succeeded) {
                return false;
            }
        }

        return true;
    }

    /** @return list<array<string, bool|int|string|null>> */
    public function toArray(): array
    {
        return array_map(
            static fn (ListenerExecutionResult $listener): array => $listener->toArray(),
            $this->listeners,
        );
    }
}
