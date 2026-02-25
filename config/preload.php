<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

if (file_exists(dirname(__DIR__) . '/var/cache/prod/srcApp_KernelProdContainer.preload.php')) {
    /** @psalm-suppress MissingFile */
    require dirname(__DIR__) . '/var/cache/prod/srcApp_KernelProdContainer.preload.php';
}

if (file_exists(dirname(__DIR__) . '/var/cache/prod/App_KernelProdContainer.preload.php')) {
    /** @psalm-suppress MissingFile */
    require dirname(__DIR__) . '/var/cache/prod/App_KernelProdContainer.preload.php';
}
