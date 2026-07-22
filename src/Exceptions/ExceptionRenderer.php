<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Exceptions;

use Throwable;

interface ExceptionRenderer
{
    /**
     * 对外输出必须使用安全内容，不得直接暴露原始异常信息。
     */
    public function render(Throwable $throwable): void;
}
