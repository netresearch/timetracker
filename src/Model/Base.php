<?php

declare(strict_types=1);

namespace App\Model;

use ReflectionClass;
use ReflectionException;
use ReflectionProperty;

use function is_object;

/**
 * Base model class with common functionality.
 */
class Base
{
    /**
     * Returns array representation of call class properties (e.g. for json_encode).
     *
     * @throws ReflectionException
     *
     * @psalm-return array<string, mixed>
     */
    public function toArray(): array
    {
        $reflectionClass = new ReflectionClass($this);

        $data = [];
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PROTECTED) as $reflectionProperty) {
            $method = 'get' . ucwords($reflectionProperty->getName());
            $callable = [$this, $method];
            if (!is_callable($callable)) {
                continue;
            }
            /** @var mixed $value */
            $value = $callable();
            if (is_object($value) && method_exists($value, 'getId')) {
                $value = $value->getId();
            }

            // Handle enums by converting to their backing value
            if (is_object($value) && enum_exists($value::class) && property_exists($value, 'value')) {
                $value = $value->value;
            }

            $name = $reflectionProperty->getName();
            $data[$name] = $value;

            // provide properties also in snake_case for BC
            // https://stackoverflow.com/questions/1993721/how-to-convert-camelcase-to-camel-case
            $name = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
            $data[$name] = $value;
        }

        return $data;
    }
}
