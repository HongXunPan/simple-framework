<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Module\Exception;

final class HelperConflictException extends ModuleException
{
    public function __construct(string $function, string $owner)
    {
        parent::__construct("全局帮助函数冲突：{$function} 已由 {$owner} 占用");
    }
}
