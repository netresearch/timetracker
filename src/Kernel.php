<?php

declare(strict_types=1);

namespace App;

use Override;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

use function assert;
use function dirname;
use function is_array;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return iterable<BundleInterface>
     */
    #[Override]
    public function registerBundles(): iterable
    {
        $contents = require $this->getProjectDir() . '/config/bundles.php';
        assert(is_array($contents));
        foreach ($contents as $class => $envs) {
            assert(is_array($envs));
            if (($envs[$this->environment] ?? $envs['all'] ?? false) === true) {
                $bundle = new $class();
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
}
