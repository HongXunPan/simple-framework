<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Exceptions;

use Throwable;

final readonly class SafeExceptionRenderer implements ExceptionRenderer
{
    private const string MESSAGE = 'Internal Server Error';

    public function render(Throwable $throwable): void
    {
        if (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
            fwrite(STDERR, self::MESSAGE . PHP_EOL);

            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }

        echo self::MESSAGE;
    }
}
