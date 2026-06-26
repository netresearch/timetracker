<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\EventSubscriber\ProfilerAdminGateSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Profiler\Profiler;

/**
 * @internal
 */
#[CoversClass(ProfilerAdminGateSubscriber::class)]
#[AllowMockObjectsWithoutExpectations]
final class ProfilerAdminGateSubscriberTest extends TestCase
{
    private function event(): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);

        return new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST);
    }

    public function testEnablesProfilerForAdmin(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::once())->method('enable');

        new ProfilerAdminGateSubscriber($security, $profiler)->onKernelRequest($this->event());
    }

    public function testLeavesProfilerDisabledForNonAdmin(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);
        $profiler = $this->createMock(Profiler::class);
        $profiler->expects(self::never())->method('enable');

        new ProfilerAdminGateSubscriber($security, $profiler)->onKernelRequest($this->event());
    }

    public function testSubscribesToKernelRequest(): void
    {
        self::assertArrayHasKey('kernel.request', ProfilerAdminGateSubscriber::getSubscribedEvents());
    }
}
