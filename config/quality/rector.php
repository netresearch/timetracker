<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\Set\SymfonySetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/../../src',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
        SymfonySetList::SYMFONY_CODE_QUALITY,
    ])
    ->withComposerBased(symfony: true)
    ->withAttributesSets(symfony: true)
    // Host-mounted cache (resolves to repo-root var/cache/rector) so the
    // ChangedFilesDetector cache persists across CI runs via actions/cache.
    // Rector salts this cache with the config-file hash, so a rule-set change
    // invalidates it — a stale restore only recomputes changed files.
    ->withCache(__DIR__ . '/../../var/cache/rector')
    ->withImportNames(importShortClasses: false);
