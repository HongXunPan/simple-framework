<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Lifecycle;

use Throwable;

final readonly class ExceptionOccurredSnapshot
{
    public function __construct(public Throwable $throwable)
    {
    }
}
