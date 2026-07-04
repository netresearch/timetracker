<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\EventSubscriber\ReleaseSessionLockSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @internal
 */
#[CoversClass(ReleaseSessionLockSubscriber::class)]
final class ReleaseSessionLockSubscriberTest extends TestCase
{
    #[DataProvider('readOnlyDataRoutes')]
    public function testSavesTheSessionForAReadOnlyDataGet(string $route): void
    {
        $session = $this->sessionExpectingSave(true);

        $this->dispatch($route, Request::METHOD_GET, $session);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function readOnlyDataRoutes(): iterable
    {
        yield 'entries' => ['_getDataDays_attr'];
        yield 'data (GET)' => ['_getData_attr'];
        yield 'time summary' => ['time_summary_attr'];
        yield 'customers (tracking)' => ['_getCustomers_attr'];
        yield 'customers (admin)' => ['_getAllCustomers_attr'];
        yield 'customer' => ['_getCustomer_attr'];
        yield 'projects (tracking)' => ['_getProjects_attr'];
        yield 'projects (admin)' => ['_getAllProjects_attr'];
        yield 'activities' => ['_getActivities_attr'];
        yield 'users (tracking)' => ['_getUsers_attr'];
        yield 'users (admin)' => ['_getAllUsers_attr'];
        yield 'teams (admin)' => ['_getAllTeams_attr'];
        yield 'ticket systems' => ['_getTicketSystems_attr'];
        yield 'ticket time summary' => ['_getTicketTimeSummary_attr'];
        yield 'holidays (admin)' => ['_getAllHolidays_attr'];
        yield 'holidays' => ['_getHolidays_attr'];
        yield 'presets (admin)' => ['_getAllPresets_attr'];
        yield 'contracts (admin)' => ['_getContracts_attr'];
        yield 'contract hours' => ['_getContractHours_attr'];
    }

    public function testLeavesOtherRoutesLocked(): void
    {
        $session = $this->sessionExpectingSave(false);

        $this->dispatch('saveEntry_attr', Request::METHOD_GET, $session);
    }

    public function testLeavesNonGetRequestsLocked(): void
    {
        // _getData_attr is a DUAL-method route (methods: ['GET', 'POST']) — the
        // real regression case: its POST verb writes and must keep its lock even
        // though the route name is on the read-only allowlist for GET.
        $session = $this->sessionExpectingSave(false);

        $this->dispatch('_getData_attr', Request::METHOD_POST, $session);
    }

    public function testIgnoresAnUnstartedSession(): void
    {
        $session = self::createMock(SessionInterface::class);
        $session->method('isStarted')->willReturn(false);
        $session->expects(self::never())->method('save');

        $this->dispatch('_getActivities_attr', Request::METHOD_GET, $session);
    }

    private function sessionExpectingSave(bool $expected): SessionInterface
    {
        $session = self::createMock(SessionInterface::class);
        $session->method('isStarted')->willReturn(true);
        $session->expects($expected ? self::once() : self::never())->method('save');

        return $session;
    }

    private function dispatch(string $route, string $method, SessionInterface $session): void
    {
        $request = Request::create('/x', $method);
        $request->attributes->set('_route', $route);
        $request->setSession($session);

        $event = new ControllerEvent(
            self::createStub(HttpKernelInterface::class),
            static fn (): string => 'ok',
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        new ReleaseSessionLockSubscriber()->onKernelController($event);
    }
}
