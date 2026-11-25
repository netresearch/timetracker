<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Docker;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_intersect;
use function array_keys;
use function array_pop;
use function array_unique;
use function array_values;
use function explode;
use function file_get_contents;
use function glob;
use function in_array;
use function is_string;
use function preg_match_all;
use function preg_replace;
use function str_starts_with;
use function substr;

use const GLOB_BRACE;

/**
 * The production image has an empty .env: each variable the configuration reads
 * needs a default in services.yaml or an entry of the app service in compose.yml.
 *
 * @internal
 */
#[CoversNothing]
final class ComposeEnvironmentTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    public function testEveryRequiredVariableIsForwardedOrHasADefault(): void
    {
        $required = $this->requiredVariables();
        self::assertContains('REQUIRE_TWO_FACTOR', $required);
        self::assertContains('DATABASE_URL', $required);

        self::assertSame([], array_values(array_diff($required, $this->forwarded(), $this->defaults())));
    }

    /**
     * An environment entry overrides an operator's env_file, and an entry without
     * a value removes what the env_file sets.
     */
    public function testAVariableWithADefaultIsNotForwarded(): void
    {
        self::assertContains('WEBAUTHN_RP_ID', $this->defaults());
        self::assertContains('APP_LOCALE', $this->forwarded());

        self::assertSame([], array_values(array_intersect($this->defaults(), $this->forwarded())));
    }

    /**
     * @return list<string>
     */
    private function requiredVariables(): array
    {
        $files = glob(self::ROOT . '/config/{*,packages/*,packages/prod/*,services/*,routes/*}.yaml', GLOB_BRACE);
        self::assertNotFalse($files);

        $names = [];
        foreach ($files as $file) {
            $source = preg_replace('/^\s*#.*$/m', '', $this->read($file));
            self::assertIsString($source);
            preg_match_all('/%env\(([^)]+)\)%/', $source, $matches);
            foreach ($matches[1] as $expression) {
                $parts = explode(':', $expression);
                $name = array_pop($parts);
                if (!in_array('default', $parts, true)) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function forwarded(): array
    {
        $compose = Yaml::parse($this->read(self::ROOT . '/compose.yml'));
        self::assertIsArray($compose);
        $services = $compose['services'] ?? null;
        self::assertIsArray($services);
        $app = $services['app'] ?? null;
        self::assertIsArray($app);
        self::assertIsArray($app['environment'] ?? null);

        $entries = [];
        foreach ($app['environment'] as $entry) {
            self::assertIsString($entry);
            $entries[] = explode('=', $entry, 2)[0];
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function defaults(): array
    {
        $config = Yaml::parse($this->read(self::ROOT . '/config/services.yaml'), Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);
        self::assertIsArray($config['parameters'] ?? null);

        $names = [];
        foreach (array_keys($config['parameters']) as $key) {
            if (is_string($key) && str_starts_with($key, 'env(')) {
                $names[] = substr($key, 4, -1);
            }
        }

        return $names;
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content, $path);

        return $content;
    }
}
