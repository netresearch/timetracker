<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\DependencyInjection\Compiler;

use App\DependencyInjection\Compiler\WebauthnAllowedOriginsPass;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webauthn\Bundle\Controller\AllowedOriginsController;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

use function array_values;

/**
 * @internal
 */
#[CoversClass(WebauthnAllowedOriginsPass::class)]
final class WebauthnAllowedOriginsPassTest extends TestCase
{
    private const string ORIGINS = '%env(origins:WEBAUTHN_ALLOWED_ORIGINS)%';

    public function testReplacesTheListAndKeepsTheSubdomainFlag(): void
    {
        $container = new ContainerBuilder();
        $factory = $container->register(CeremonyStepManagerFactory::class)
            ->addMethodCall('setSecuredRelyingPartyId', [[]])
            ->addMethodCall('setAllowedOrigins', [['%env(WEBAUTHN_ALLOWED_ORIGINS)%'], false]);
        $controller = $container->register(AllowedOriginsController::class)
            ->addArgument(['%env(WEBAUTHN_ALLOWED_ORIGINS)%']);

        new WebauthnAllowedOriginsPass()->process($container);

        self::assertSame(
            [['setSecuredRelyingPartyId', [[]]], ['setAllowedOrigins', [self::ORIGINS, '%webauthn.allow_subdomains%']]],
            array_values($factory->getMethodCalls()),
        );
        self::assertSame([self::ORIGINS], $controller->getArguments());
    }

    public function testFailsTheBuildWhenTheBundleNoLongerSetsTheOrigins(): void
    {
        $container = new ContainerBuilder();
        $container->register(CeremonyStepManagerFactory::class);
        $container->register(AllowedOriginsController::class)->addArgument([]);

        $this->expectException(LogicException::class);

        new WebauthnAllowedOriginsPass()->process($container);
    }
}
