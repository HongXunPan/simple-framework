<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Execution;

final readonly class ErrorMessageSanitizer
{
    public function sanitize(string $message): string
    {
        $message = preg_replace('/[\r\n\t]+/', ' ', trim($message)) ?? '';
        $message = preg_replace(
            '/\b(token|password|secret|openid|cookie)\s*[:=]\s*[^\s,;]+/i',
            '$1=[REDACTED]',
            $message,
        ) ?? $message;
        $message = preg_replace('/\b1[3-9]\d{9}\b/', '1**********', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}
