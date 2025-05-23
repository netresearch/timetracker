<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Model;

use ReflectionClass;
use ReflectionProperty;

/*
 * Base model
 */

/**
 * Class Base
 * @package App\Model
 */
class Base
{
    /**
     * Returns array representation of call class properties (e.g. for json_encode)
     *
     * @throws \ReflectionException
     */
    public function toArray(): array
    {
        $reflectionClass = new ReflectionClass($this);

        $data = [];
        foreach ($reflectionClass->getProperties(ReflectionProperty::IS_PROTECTED) as $reflectionProperty) {
            $method = 'get' . ucwords($reflectionProperty->getName());
            $value = $this->$method();
            if (is_object($value) && method_exists($value, 'getId')) {
                $value = $value->getId();
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
