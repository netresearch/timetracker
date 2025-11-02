<?php

declare(strict_types=1);

namespace App;

use Generator;
use Override;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

use function dirname;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): Generator
    {
        $contents = require $this->getProjectDir() . '/config/bundles.php';
        assert(is_array($contents));
        foreach ($contents as $class => $envs) {
            assert(is_array($envs));
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }

    #[Override]
    public function getProjectDir(): string
    {
        return dirname(__DIR__);
    }
}
