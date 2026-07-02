<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

final class ArchitectureTest
{
    private const string NAMESPACE_ENTITY = 'App\Entity';

    private const string NAMESPACE_SERVICE = 'App\Service';

    private const string NAMESPACE_ENUM = 'App\Enum';

    private const string NAMESPACE_REPOSITORY = 'App\Repository';

    private const string CLASSNAME_DATETIME = 'DateTime*';

    private const string CLASSNAME_EXCEPTION = '*Exception';

    public function testControllersShouldOnlyDependOnBusinessLogic(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Controller'))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ENTITY),
                Selector::inNamespace(self::NAMESPACE_SERVICE),
                Selector::inNamespace('App\Dto'),
                Selector::inNamespace(self::NAMESPACE_ENUM),
                Selector::inNamespace('App\Event'),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Doctrine'),
                Selector::classname(self::CLASSNAME_DATETIME, true),
                Selector::classname(self::CLASSNAME_EXCEPTION, true),
            )
            ->because('Controllers should only depend on business logic and framework components');
    }

    public function testEntitiesShouldBePureDataModels(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_ENTITY))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ENUM),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Symfony\Component\Validator'),
                Selector::classname('DateTimeInterface'),
                Selector::classname(self::CLASSNAME_DATETIME, true),
            )
            ->because('Entities should be pure data models with minimal dependencies');
    }

    public function testServicesCanOrchestrateBusinessLogic(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_SERVICE))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ENTITY),
                Selector::inNamespace(self::NAMESPACE_REPOSITORY),
                Selector::inNamespace('App\Dto'),
                Selector::inNamespace(self::NAMESPACE_ENUM),
                Selector::inNamespace('App\Event'),
                Selector::inNamespace(self::NAMESPACE_SERVICE),
                Selector::inNamespace('Symfony'),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Psr'),
                Selector::inNamespace('GuzzleHttp'),
                Selector::classname(self::CLASSNAME_DATETIME, true),
                Selector::classname(self::CLASSNAME_EXCEPTION, true),
            )
            ->because('Services should handle business logic and orchestration');
    }

    public function testRepositoriesShouldOnlyHandleDataAccess(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::NAMESPACE_REPOSITORY))
            ->canOnly()->dependOn()
            ->classes(
                Selector::inNamespace(self::NAMESPACE_ENTITY),
                Selector::inNamespace(self::NAMESPACE_ENUM),
                Selector::inNamespace('Doctrine'),
                Selector::inNamespace('Symfony\Component\Security'),
                Selector::classname(self::CLASSNAME_DATETIME, true),
                Selector::classname(self::CLASSNAME_EXCEPTION, true),
            )
            ->because('Repositories should only handle data access');
    }

    public function testControllersMustNotDirectlyAccessRepositories(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Controller'))
            ->shouldNot()->dependOn()
            ->classes(Selector::inNamespace(self::NAMESPACE_REPOSITORY))
            ->because('Controllers should use Services, not Repositories directly');
    }
}
