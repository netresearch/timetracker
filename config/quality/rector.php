<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector;
use Rector\Renaming\Rector\MethodCall\RenameMethodRector;
use Rector\Renaming\Rector\Name\RenameClassRector;
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
    ->withSkip([
        // Symfony 8.1 deprecates HttpKernel's BundleInterface in favour of the
        // DependencyInjection one, and rector rewrites the import. Kernel.php
        // must keep the deprecated interface until Symfony 9 changes the parent
        // registerBundles() signature — the replacement is not covariant with
        // it. See the docblock on Kernel::registerBundles().
        RenameClassRector::class => [
            __DIR__ . '/../../src/Kernel.php',
        ],
        // Renames UserInterface::getUsername() to getUserIdentifier() because
        // Symfony dropped the former from the interface. The two are not the
        // same value here: User::getUserIdentifier() substitutes '_' for an
        // empty username to honour Symfony's non-empty contract, while
        // getUsername() returns what is stored. These three sites report or key
        // on the stored name, so the placeholder would leak into API responses
        // (the two DTOs) and into the remote author keys that decide PO sync
        // coverage — where it would also make the following '' !== $username
        // guard unreachable.
        RenameMethodRector::class => [
            __DIR__ . '/../../src/Dto/Response/SyncConflictDto.php',
            __DIR__ . '/../../src/Dto/Response/SyncRunDto.php',
            __DIR__ . '/../../src/Service/Sync/SyncWorklogsService.php',
        ],
        // Strips `= null` from properties it believes are always assigned, but
        // it does not see Symfony's #[Required] setter injection: those
        // properties are assigned by the container AFTER construction, so
        // without the default they stay uninitialized. The `instanceof` guards
        // that read them (BulkEntryAction, DeleteEntryAction) then raise
        // "Typed property must not be accessed before initialization" instead
        // of taking the null branch. Skipped repo-wide rather than per file:
        // the rule only fires on classes that have a constructor, so a class
        // with the same setter-injected property is silently exempt until
        // someone adds one.
        RemoveDefaultValueFromAssignedPropertyRector::class,
    ])
    // Host-mounted cache (resolves to repo-root var/cache/rector) so the
    // ChangedFilesDetector cache persists across CI runs via actions/cache.
    // Rector salts this cache with the config-file hash, so a rule-set change
    // invalidates it — a stale restore only recomputes changed files.
    ->withCache(__DIR__ . '/../../var/cache/rector')
    ->withImportNames(importShortClasses: false);
