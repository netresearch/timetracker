<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webauthn\Bundle\Controller\AllowedOriginsController;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

use function sprintf;

/**
 * Makes WEBAUTHN_ALLOWED_ORIGINS a list. The bundle's allowed_origins node is an
 * array and takes no placeholder for the whole list, so webauthn.yaml passes the
 * variable as one entry. This pass replaces the list in both places the bundle
 * copies it to: the ceremony check and /.well-known/webauthn. Registered from
 * Kernel::build() AFTER the bundle's CeremonyStepManagerFactoryCompilerPass.
 */
final class WebauthnAllowedOriginsPass implements CompilerPassInterface
{
    private const string ORIGINS = '%env(origins:WEBAUTHN_ALLOWED_ORIGINS)%';

    public function process(ContainerBuilder $container): void
    {
        $factory = $container->getDefinition(CeremonyStepManagerFactory::class);
        if (!$factory->hasMethodCall('setAllowedOrigins')) {
            throw new LogicException(sprintf('%s has no setAllowedOrigins() call to replace.', CeremonyStepManagerFactory::class));
        }

        $factory->removeMethodCall('setAllowedOrigins');
        $factory->addMethodCall('setAllowedOrigins', [self::ORIGINS, '%webauthn.allow_subdomains%']);
        $container->getDefinition(AllowedOriginsController::class)->replaceArgument(0, self::ORIGINS);
    }
}
