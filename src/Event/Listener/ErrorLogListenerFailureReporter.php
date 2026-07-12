<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Listener;

use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Execution\ErrorMessageSanitizer;
use Throwable;

final readonly class ErrorLogListenerFailureReporter implements ListenerFailureReporter
{
    public function __construct(private ErrorMessageSanitizer $errors)
    {
    }

    public function report(string $listenerClass, Event $event, Throwable $throwable): void
    {
        $context = json_encode([
            'listener_class' => $listenerClass,
            'event_class' => $event::class,
            'exception_class' => $throwable::class,
            'error_message' => $this->errors->sanitize($throwable->getMessage()),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        error_log('[simple-framework:event:best-effort] ' . ($context ?: '{}'));
    }
}
