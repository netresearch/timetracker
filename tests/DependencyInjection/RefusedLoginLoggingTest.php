<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\DependencyInjection;

use App\Security\LoginFormAuthenticator;
use App\Service\Ldap\LdapClientService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function dirname;

/**
 * A refused login is a warning, which the fingers_crossed handler drops. It stays
 * on record only while the services log to the ldap channel and that channel has
 * a handler of its own.
 *
 * @internal
 */
#[CoversNothing]
final class RefusedLoginLoggingTest extends TestCase
{
    #[DataProvider('ldapLoggingServiceProvider')]
    public function testServiceLogsToTheLdapChannel(string $service): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);
        self::assertIsArray($config['services'] ?? null);
        self::assertIsArray($config['services'][$service] ?? null);

        self::assertSame([['name' => 'monolog.logger', 'channel' => 'ldap']], $config['services'][$service]['tags'] ?? null);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function ldapLoggingServiceProvider(): iterable
    {
        yield 'authenticator' => [LoginFormAuthenticator::class];
        yield 'client' => [LdapClientService::class];
    }

    #[DataProvider('environmentProvider')]
    public function testLdapChannelIsWrittenFromWarningOn(string $environment): void
    {
        $config = Yaml::parseFile(dirname(__DIR__, 2) . '/config/packages/' . $environment . '/monolog.yaml');
        self::assertIsArray($config);
        self::assertIsArray($config['monolog'] ?? null);
        self::assertIsArray($config['monolog']['handlers'] ?? null);
        $handler = $config['monolog']['handlers']['ldap'] ?? null;
        self::assertIsArray($handler);

        self::assertSame(['stream', 'warning', ['ldap']], [$handler['type'] ?? null, $handler['level'] ?? null, $handler['channels'] ?? null]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function environmentProvider(): iterable
    {
        yield 'prod' => ['prod'];
        yield 'profiling' => ['profiling'];
    }
}
