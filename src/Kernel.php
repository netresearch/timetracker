<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App;

use App\DependencyInjection\Compiler\RemoveSensitiveCollectorsPass;
use Override;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

use function assert;
use function dirname;
use function is_array;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * The deprecated HttpKernel BundleInterface must stay until Symfony 9
     * changes the parent registerBundles() signature — the replacement
     * interface is not covariant with it.
     *
     * @return iterable<BundleInterface>
     */
    #[Override]
    // @phpstan-ignore return.deprecatedInterface
    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir() . '/config/bundles.php';
        assert(is_array($contents));
        foreach ($contents as $class => $envs) {
            assert(is_array($envs));
            if (($envs[$this->environment] ?? $envs['all'] ?? false) === true) {
                $bundle = new $class();
                // @phpstan-ignore instanceof.deprecatedInterface
                assert($bundle instanceof BundleInterface);
                yield $bundle;
            }
        }
    }

    #[Override]
    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }

    #[Override]
    protected function build(ContainerBuilder $container): void
    {
        // The profiling image enables the web profiler; strip the data
        // collectors that could surface env/secret data (dump, config). Priority
        // 1000 runs this before Symfony's ProfilerPass (priority 0) reads the
        // `data_collector` tags, so the untagged collectors are never registered.
        if ('profiling' === $this->environment) {
            $container->addCompilerPass(
                new RemoveSensitiveCollectorsPass(),
                PassConfig::TYPE_BEFORE_OPTIMIZATION,
                1000,
            );
        }
    }
}
