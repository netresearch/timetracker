<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace Netresearch\TimeTrackerBundle\Model;

use ReflectionClass as ReflectionClass;
use ReflectionProperty as ReflectionProperty;

/*
 * Base model
 */

/**
 * Class Base
 * @package Netresearch\TimeTrackerBundle\Model
 */
class Base
{
    /**
     * Returns array representation of call class properties (e.g. for json_encode)
     *
     * @return array
     * @throws \ReflectionException
     */
    public function toArray()
    {
        $r = new ReflectionClass($this);

        $data = array();
        foreach ($r->getProperties(ReflectionProperty::IS_PROTECTED) as $property) {
            $method = 'get' . ucwords($property->getName());
            $data[$property->getName()] = $this->$method();
        }

        return $data;
    }
}
