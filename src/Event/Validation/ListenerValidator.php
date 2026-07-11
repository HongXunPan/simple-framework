<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Validation;

use HongXunPan\Framework\Event\Exception\EventConfigException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class ListenerValidator
{
    /**
     * @param class-string $listenerClass
     * @param class-string $eventClass
     */
    public function validate(string $listenerClass, string $eventClass): void
    {
        if (!class_exists($listenerClass)) {
            throw new EventConfigException("事件监听器不存在：{$listenerClass}");
        }

        $listener = new ReflectionClass($listenerClass);
        if (!$listener->isInstantiable()) {
            throw new EventConfigException("事件监听器必须是可实例化类：{$listenerClass}");
        }

        if (!$listener->hasMethod('handle')) {
            throw new EventConfigException("事件监听器必须声明 handle 方法：{$listenerClass}");
        }

        $handle = $listener->getMethod('handle');
        if (!$handle->isPublic() || $handle->isStatic()) {
            throw new EventConfigException("事件监听器 handle 必须是公开实例方法：{$listenerClass}");
        }

        $this->validateParameter($handle, $listenerClass, $eventClass);
        $this->validateReturnType($handle, $listenerClass);
    }

    private function validateParameter(
        ReflectionMethod $handle,
        string $listenerClass,
        string $eventClass,
    ): void {
        $parameters = $handle->getParameters();
        if (count($parameters) !== 1) {
            throw new EventConfigException("事件监听器 handle 必须且只能接收一个参数：{$listenerClass}");
        }

        $parameter = $parameters[0];
        $type = $parameter->getType();
        if (
            !$type instanceof ReflectionNamedType
            || $type->isBuiltin()
            || $type->allowsNull()
            || $type->getName() !== $eventClass
            || $parameter->isVariadic()
            || $parameter->isPassedByReference()
        ) {
            throw new EventConfigException(
                "事件监听器 handle 参数必须精确声明为 {$eventClass}：{$listenerClass}",
            );
        }
    }

    private function validateReturnType(ReflectionMethod $handle, string $listenerClass): void
    {
        $returnType = $handle->getReturnType();
        if (
            !$returnType instanceof ReflectionNamedType
            || !$returnType->isBuiltin()
            || $returnType->getName() !== 'void'
        ) {
            throw new EventConfigException("事件监听器 handle 必须显式返回 void：{$listenerClass}");
        }
    }
}
