<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Traits;

use function getenv;
use function putenv;

/**
 * Removes variables from every place Symfony reads them from, as if they were
 * missing from the container environment.
 */
trait RemovesEnvironmentVariablesTrait
{
    /** @var array{env: array<mixed>, server: array<mixed>, process: array<string, string>}|null */
    private ?array $environmentBackup = null;

    /**
     * @param list<string> $names
     */
    private function removeEnvironmentVariables(array $names): void
    {
        $this->environmentBackup ??= ['env' => $_ENV, 'server' => $_SERVER, 'process' => []];

        foreach ($names as $name) {
            $value = getenv($name, true);
            if (false !== $value) {
                $this->environmentBackup['process'][$name] = $value;
            }

            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    private function restoreEnvironmentVariables(): void
    {
        if (null === $this->environmentBackup) {
            return;
        }

        $_ENV = $this->environmentBackup['env'];
        $_SERVER = $this->environmentBackup['server'];
        foreach ($this->environmentBackup['process'] as $name => $value) {
            putenv($name . '=' . $value);
        }

        $this->environmentBackup = null;
    }
}
