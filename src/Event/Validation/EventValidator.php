<?php

declare(strict_types=1);

namespace HongXunPan\Framework\Event\Validation;

use BackedEnum;
use DateTimeImmutable;
use HongXunPan\Framework\Event\Event;
use HongXunPan\Framework\Event\Exception\EventConfigException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;

final class EventValidator
{
    /** @var array<class-string<Event>, true> */
    private array $validated = [];

    /** @param class-string $eventClass */
    public function validate(string $eventClass): void
    {
        if (isset($this->validated[$eventClass])) {
            return;
        }
        if (!class_exists($eventClass)) {
            throw new EventConfigException("事件类不存在：{$eventClass}");
        }

        $event = new ReflectionClass($eventClass);
        if (!$event->isInstantiable() || !$event->implementsInterface(Event::class)) {
            throw new EventConfigException("事件类必须是可实例化的 Event：{$eventClass}");
        }
        if (!$event->isFinal() || !$event->isReadOnly()) {
            throw new EventConfigException("事件类必须声明为 final readonly：{$eventClass}");
        }

        $this->validateSnapshot($event);
        $this->readVersion($event);
        $this->validated[$eventClass] = true;
    }

    /** @param class-string<Event> $eventClass */
    public function versionOf(string $eventClass): int
    {
        $this->validate($eventClass);

        return $this->readVersion(new ReflectionClass($eventClass));
    }

    /** @param ReflectionClass<Event> $event */
    private function validateSnapshot(ReflectionClass $event): void
    {
        $properties = [];
        foreach ($event->getProperties() as $property) {
            if ($property->isStatic() || !$property->isPublic()) {
                throw new EventConfigException(
                    "Event 快照属性必须是公开实例属性：{$event->getName()}::\${$property->getName()}",
                );
            }

            $this->validatePropertyType($event->getName(), $property);
            $properties[$property->getName()] = $property;
        }

        $constructor = $event->getConstructor();
        if ($constructor === null) {
            if ($properties !== []) {
                throw new EventConfigException("有快照属性的 Event 必须声明构造方法：{$event->getName()}");
            }

            return;
        }

        $parameters = [];
        foreach ($constructor->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        foreach ($properties as $name => $property) {
            $parameter = $parameters[$name] ?? null;
            if ($parameter === null || !$this->hasSameType($property, $parameter)) {
                throw new EventConfigException(
                    "Event 属性必须有同名同类型构造参数：{$event->getName()}::\${$name}",
                );
            }
        }

        foreach ($parameters as $name => $parameter) {
            if (!isset($properties[$name]) || $parameter->isVariadic() || $parameter->isPassedByReference()) {
                throw new EventConfigException(
                    "Event 构造参数必须对应公开快照属性：{$event->getName()}::\${$name}",
                );
            }
        }
    }

    private function validatePropertyType(string $eventClass, ReflectionProperty $property): void
    {
        $type = $property->getType();
        if (!$type instanceof ReflectionNamedType) {
            throw new EventConfigException(
                "Event 属性必须使用单一显式类型：{$eventClass}::\${$property->getName()}",
            );
        }

        $typeName = $type->getName();
        if ($type->isBuiltin()) {
            if (in_array($typeName, ['bool', 'int', 'float', 'string', 'null'], true)) {
                return;
            }
        } elseif ($typeName === DateTimeImmutable::class
            || (enum_exists($typeName) && is_subclass_of($typeName, BackedEnum::class))) {
            return;
        }

        throw new EventConfigException(
            "Event 属性类型不在 MVP 白名单：{$eventClass}::\${$property->getName()} ({$typeName})",
        );
    }

    private function hasSameType(ReflectionProperty $property, ReflectionParameter $parameter): bool
    {
        $propertyType = $property->getType();
        $parameterType = $parameter->getType();

        return $propertyType instanceof ReflectionNamedType
            && $parameterType instanceof ReflectionNamedType
            && (string)$propertyType === (string)$parameterType;
    }

    /** @param ReflectionClass<Event> $event */
    private function readVersion(ReflectionClass $event): int
    {
        $constant = $event->getReflectionConstant('VERSION');
        if ($constant === false) {
            return 1;
        }

        $version = $constant->getValue();
        if (!$constant->isPublic() || !is_int($version) || $version < 1) {
            throw new EventConfigException("Event VERSION 必须是公开正整数：{$event->getName()}");
        }

        return $version;
    }
}
