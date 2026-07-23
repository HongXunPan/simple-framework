<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Console;

final class Output
{
    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    public function __construct(mixed $stdout = null, mixed $stderr = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
    }

    public function line(string $message = ''): void
    {
        fwrite($this->stdout, $message . PHP_EOL);
    }

    public function error(string $message): void
    {
        fwrite($this->stderr, $message . PHP_EOL);
    }
}
