<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Execution;

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
}
