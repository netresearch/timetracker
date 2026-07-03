<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle::class => ['all' => true],
    Sentry\SentryBundle\SentryBundle::class => ['all' => true],
    Symfony\Bundle\MakerBundle\MakerBundle::class => ['dev' => true],
    Symfony\Bundle\MonologBundle\MonologBundle::class => ['all' => true],
    Pentatrion\ViteBundle\PentatrionViteBundle::class => ['all' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true, 'profiling' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class => ['dev' => true, 'test' => true, 'profiling' => true],
    Scheb\TwoFactorBundle\SchebTwoFactorBundle::class => ['all' => true],
    Webauthn\Bundle\WebauthnBundle::class => ['all' => true],
];
