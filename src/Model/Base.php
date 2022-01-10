<?php declare(strict_types=1);
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

namespace App\Model;

use ReflectionException;
use ReflectionClass;
use ReflectionProperty;

/*
 * Base model
 */

/**
 * Class Base.
 */
class Base
{
    /**
     * Returns array representation of call class properties (e.g. for json_encode).
     *
     * @throws ReflectionException
     *
     * @return array
     */
    public function toArray()
    {
        $r = new ReflectionClass($this);

        $data = [];
        foreach ($r->getProperties(ReflectionProperty::IS_PROTECTED) as $property) {
            $method = 'get'.ucwords($property->getName());
            $value  = $this->{$method}();
            if (\is_object($value) && method_exists($value, 'getId')) {
                $value = $value->getId();
            }

            $name        = $property->getName();
            $data[$name] = $value;

            // provide properties also in snake_case for BC
            // https://stackoverflow.com/questions/1993721/how-to-convert-camelcase-to-camel-case
            $name        = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
            $data[$name] = $value;
        }

        return $data;
    }
}
