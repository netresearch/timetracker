<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\PersonioConfig;
use PHPUnit\Framework\TestCase;

final class PersonioConfigTest extends TestCase
{
    public function testFluentSettersAndDefaults(): void
    {
        $config = new PersonioConfig();

        self::assertTrue($config->getActive());

        $config->setName('Personio')->setBaseUrl('https://api.personio.de')->setClientId('cid')->setClientSecret('enc')->setActive(false);

        self::assertSame('Personio', $config->getName());
        self::assertSame('https://api.personio.de', $config->getBaseUrl());
        self::assertSame('cid', $config->getClientId());
        self::assertSame('enc', $config->getClientSecret());
        self::assertFalse($config->getActive());
    }

    public function testToSafeArrayStripsClientSecret(): void
    {
        $config = new PersonioConfig();
        $config->setName('Personio')->setClientId('cid')->setClientSecret('enc');

        $safe = $config->toSafeArray();

        self::assertArrayHasKey('name', $safe);
        self::assertArrayNotHasKey('clientSecret', $safe);
        self::assertArrayNotHasKey('client_secret', $safe);
    }
}
