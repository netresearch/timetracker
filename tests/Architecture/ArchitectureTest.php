<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Layer rules enforced by PHPat (run via config/quality/phpat.neon).
 *
 * The allow-lists codify the codebase's CURRENT architecture — including
 * repository injection into invokable controller actions — so the gate
 * catches future drift without demanding a big-bang refactor. Tighten a
 * list only together with the refactor that makes it true.
 */
final class ArchitectureTest
{
    private const string NAMESPACE_ENTITY = 'App\Entity';

    private const string NAMESPACE_SERVICE = 'App\Service';

    private const string NAMESPACE_ENUM = 'App\Enum';

    private const string NAMESPACE_REPOSITORY = 'App\Repository';

    private const string NAMESPACE_DTO = 'App\Dto';

    private const string NAMESPACE_EXCEPTION = 'App\Exception';

    private const string NAMESPACE_MODEL = 'App\Model';

    private const string CLASSNAME_EXCEPTION = '*Exception';

    public function testControllersShouldOnlyDependOnBusinessLogic(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Controller'))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace('App\Controller'),
                Selector::inNamespace(self::NAMESPACE_ENTITY),
                Selector::inNamespace(self::NAMESPACE_SERVICE),
                Selector::inNamespace(self::NAMESPACE_REPOSITORY),
                Selector::inNamespace(self::NAMESPACE_DTO),
                Selector::inNamespace('App\DTO'),
                Selector::inNamespace(self::NAMESPACE_ENUM),
                Selector::inNamespace('App\Event'),
                Selector::inNamespace(self::NAMESPACE_EXCEPTION),
                Selector::inNamespace(self::NAMESPACE_MODEL),
                Selector::inNamespace('App\Response'),
                Selector::inNamespace('App\Util'),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Twig'),
                Selector::inNamespace('PhpOffice\PhpSpreadsheet'),
                Selector::inNamespace('Composer'),
                Selector::inNamespace('Psr'),
                Selector::classname(self::CLASSNAME_EXCEPTION, true),
            )
            ->because('Controllers depend on the app layers, framework and export/status tooling only');
    }

    public function testEntitiesShouldBePureDataModels(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ENTITY))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ENTITY),
                Selector::inNamespace(self::NAMESPACE_REPOSITORY),
                Selector::inNamespace(self::NAMESPACE_ENUM),
                Selector::inNamespace(self::NAMESPACE_SERVICE),
                Selector::inNamespace(self::NAMESPACE_EXCEPTION),
                Selector::inNamespace(self::NAMESPACE_MODEL),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Symfony'),
                Selector::classname(self::CLASSNAME_EXCEPTION, true),
            )
            ->because('Entities stay data-centric: relations, ORM metadata, validation');
    }

    public function testServicesCanOrchestrateBusinessLogic(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_SERVICE))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ENTITY),
                Selector::inNamespace(self::NAMESPACE_REPOSITORY),
                Selector::inNamespace(self::NAMESPACE_DTO),
                Selector::inNamespace('App\DTO'),
                Selector::inNamespace(self::NAMESPACE_ENUM),
                Selector::inNamespace('App\Event'),
                Selector::inNamespace(self::NAMESPACE_EXCEPTION),
                Selector::inNamespace(self::NAMESPACE_MODEL),
                Selector::inNamespace('App\Response'),
                Selector::inNamespace('App\ValueObject'),
                Selector::inNamespace(self::NAMESPACE_SERVICE),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Psr'),
                Selector::inNamespace('GuzzleHttp'),
                Selector::inNamespace('Laminas'),
                Selector::classname(self::CLASSNAME_EXCEPTION, true),
            )
            ->because('Services orchestrate business logic over the data and integration layers');
    }

    public function testRepositoriesShouldOnlyHandleDataAccess(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_REPOSITORY))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ENTITY),
                Selector::inNamespace(self::NAMESPACE_REPOSITORY),
                Selector::inNamespace(self::NAMESPACE_ENUM),
                Selector::inNamespace(self::NAMESPACE_SERVICE),
                Selector::inNamespace(self::NAMESPACE_DTO),
                Selector::inNamespace(self::NAMESPACE_MODEL),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Psr'),
                Selector::classname(self::CLASSNAME_EXCEPTION, true),
            )
            ->because('Repositories handle data access (plus the query-helper services they delegate to)');
    }
}
