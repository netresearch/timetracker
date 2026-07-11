<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Architecture;

use App\Security\ApiToken\ApiAccessToken;
use App\Security\ApiToken\RequireScope;
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
                // The #[RequireScope] API-token attribute (ADR-021) — a security
                // attribute controllers declare, analogous to Symfony's #[IsGranted].
                Selector::classname(RequireScope::class),
                // The ApiAccessToken security token (ADR-021) — SaveEntryAction
                // reads it via TokenStorage to gate agent-vs-human attribution
                // (ADR-025 §4), the same way a controller reads Symfony's token.
                Selector::classname(ApiAccessToken::class),
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
                // scheb 2FA contract interfaces the User entity must implement
                // (TotpTwoFactorInterface, BackupCodeInterface, TotpConfiguration)
                // — a framework contract like Symfony's UserInterface. ADR-018 D2.
                Selector::inNamespace('Scheb\TwoFactorBundle\Model'),
                // WebAuthn credential model the passkey entity extends (ADR-018 D3),
                // another framework-contract superclass like the scheb one above.
                Selector::inNamespace('Webauthn'),
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
                // TOTP library backing the 2FA enrolment service (ADR-018 D2),
                // an integration dependency like Laminas (LDAP) above.
                Selector::inNamespace('OTPHP'),
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
                // WebAuthn credential/user-entity contracts the passkey repositories
                // implement + return (ADR-018 D3) — the bundle's data-layer types.
                Selector::inNamespace('Webauthn'),
                Selector::classname(self::CLASSNAME_EXCEPTION, true),
            )
            ->because('Repositories handle data access (plus the query-helper services they delegate to)');
    }
}
