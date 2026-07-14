<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\UserLocaleSubscriber;
use App\Service\Util\LocalizationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * @internal
 */
#[CoversClass(UserLocaleSubscriber::class)]
final class UserLocaleSubscriberTest extends TestCase
{
    private const string DEFAULT_LOCALE = 'en';

    private LocaleSwitcher $localeSwitcher;

    protected function setUp(): void
    {
        $this->localeSwitcher = new LocaleSwitcher(self::DEFAULT_LOCALE, []);
    }

    public function testAppliesTheUsersSavedLocaleToRequestAndTranslator(): void
    {
        $request = Request::create('/ui');

        $this->dispatch($request, user: self::userWithLocale('de'));

        self::assertSame('de', $request->getLocale());
        self::assertSame('de', $this->localeSwitcher->getLocale());
    }

    public function testNormalizesAnUnsupportedLegacyLocale(): void
    {
        $request = Request::create('/ui');

        $this->dispatch($request, user: self::userWithLocale('xx'));

        self::assertSame('en', $request->getLocale());
        self::assertSame('en', $this->localeSwitcher->getLocale());
    }

    public function testAnonymousRequestKeepsTheDefaultLocale(): void
    {
        $request = Request::create('/login');

        $this->dispatch($request, user: null);

        self::assertSame(self::DEFAULT_LOCALE, $request->getLocale());
        self::assertSame(self::DEFAULT_LOCALE, $this->localeSwitcher->getLocale());
    }

    public function testErrorSubRequestGetsTheUsersLocale(): void
    {
        // Mirror ErrorListener::duplicateRequest(): the error sub-request is a
        // clone of the failing request whose attributes are REPLACED, so no
        // `_locale` survives — the user's locale must still be applied.
        $subRequest = Request::create('/ui')->duplicate(null, null, [
            '_controller' => 'error_controller',
            'exception' => new RuntimeException('boom'),
        ]);

        $this->dispatch($subRequest, user: self::userWithLocale('de'), requestType: HttpKernelInterface::SUB_REQUEST);

        self::assertSame('de', $subRequest->getLocale());
        self::assertSame('de', $this->localeSwitcher->getLocale());
    }

    public function testSubRequestWithAnExplicitLocaleIsLeftUntouched(): void
    {
        // A sub-request dispatched in a fixed locale (fragment, localized
        // rendering) carries `_locale`; the user's saved locale must not win.
        $subRequest = Request::create('/_fragment');
        $subRequest->attributes->set('_locale', 'fr');
        $subRequest->setLocale('fr');

        $this->dispatch($subRequest, user: self::userWithLocale('de'), requestType: HttpKernelInterface::SUB_REQUEST);

        self::assertSame('fr', $subRequest->getLocale());
        self::assertSame(self::DEFAULT_LOCALE, $this->localeSwitcher->getLocale());
    }

    private function dispatch(
        Request $request,
        ?User $user,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): void {
        $security = self::createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $subscriber = new UserLocaleSubscriber($security, $this->localeSwitcher, new LocalizationService());
        $subscriber->onKernelRequest(new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $requestType));
    }

    private static function userWithLocale(string $locale): User
    {
        $user = new User();
        $user->setLocale($locale);

        return $user;
    }
}
