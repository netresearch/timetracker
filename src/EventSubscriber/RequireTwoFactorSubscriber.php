<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\Security\TwoFactorStatusService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

use function array_any;
use function str_contains;
use function str_starts_with;

/**
 * Org-wide mandatory 2FA (ADR-018): when %app_require_two_factor% is on, an
 * authenticated user who has NEITHER TOTP NOR a passkey is confined to the
 * enrolment surface until they set one up.
 *
 * Fail-closed: only an explicit allowlist (the SPA shell that hosts the gate, the
 * enrolment endpoints, the login/logout ceremony, and static assets) is reachable;
 * every other route is blocked. Blocked API/XHR calls get a 403 JSON envelope
 * (the real security boundary — the SPA also gates itself from APP_CONFIG); a
 * blocked HTML navigation is redirected to the SPA shell, where the gate renders.
 *
 * The scheb 2FA login challenge (IS_AUTHENTICATED_2FA_IN_PROGRESS) is deliberately
 * exempt: a half-finished login is not the same as "no second factor".
 */
final readonly class RequireTwoFactorSubscriber implements EventSubscriberInterface
{
    private const string MIME_TYPE_JSON = 'application/json';

    /**
     * Path prefixes reachable without a second factor while enrolment is mandatory.
     * Matched with a STRICT boundary ({@see isAllowlisted}) — exact, or the prefix
     * followed by '/' — so a future route like /settings/2fa_bypass or /status_admin
     * can't slip through by sharing a leading substring. The underscore-suffixed
     * login/2FA ceremony paths are therefore listed explicitly.
     *
     * @var list<string>
     */
    private const array ALLOWLIST_PREFIXES = [
        '/ui',                          // SPA shell — hosts the enrolment gate
        '/login',                       // /login, /login/options, /login/passkey
        '/login_check',
        '/logout',
        '/2fa',                         // /2fa (login challenge page)
        '/2fa_check',
        '/settings/2fa',                // TOTP start / confirm / disable
        '/settings/security/passkeys',  // passkey register / list / delete
        '/status',                      // health checks
        '/build-ui',                    // Vite-built assets
        '/css',
        '/js',
        '/images',
    ];

    public function __construct(
        private Security $security,
        private TwoFactorStatusService $status,
        private RouterInterface $router,
        #[Autowire('%app_require_two_factor%')]
        private bool $required,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 7: after the firewall (8) has populated the token, before the
        // controller runs.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    public function onKernelRequest(RequestEvent $requestEvent): void
    {
        if (!$this->mustEnrol($requestEvent) || $this->isAllowlisted($requestEvent->getRequest()->getPathInfo())) {
            return;
        }

        // An explicit HTML navigation lands on the SPA gate; an API/XHR call is
        // refused outright.
        $acceptHeader = (string) $requestEvent->getRequest()->headers->get('Accept', '');
        $prefersHtml = str_contains($acceptHeader, 'text/html') && !str_contains($acceptHeader, self::MIME_TYPE_JSON);

        $requestEvent->setResponse($prefersHtml
            ? new RedirectResponse($this->router->generate('ui_spa'))
            : new JsonResponse([
                'error' => 'TwoFactorRequired',
                'message' => 'Two-factor authentication is required. Set it up to continue.',
            ], Response::HTTP_FORBIDDEN));
    }

    /**
     * Whether this request belongs to a user who is obliged to enrol: the flag is
     * on, the request is a main request by an authenticated user, the login is not
     * mid-2FA-challenge (that is scheb's flow, not "missing 2FA"), and the user
     * has no second factor yet. Unauthenticated requests are the firewall's
     * access_control concern, never ours.
     */
    private function mustEnrol(RequestEvent $requestEvent): bool
    {
        if (!$this->required || !$requestEvent->isMainRequest()) {
            return false;
        }

        $user = $this->security->getUser();

        return $user instanceof User
            && !$this->security->isGranted('IS_AUTHENTICATED_2FA_IN_PROGRESS')
            && !$this->status->hasTwoFactor($user);
    }

    private function isAllowlisted(string $path): bool
    {
        // Strict boundary: an exact match, or the prefix followed by '/'. A bare
        // str_starts_with would let /settings/2fa_bypass ride in on /settings/2fa.
        return array_any(
            self::ALLOWLIST_PREFIXES,
            static fn (string $prefix): bool => $path === $prefix || str_starts_with($path, $prefix . '/'),
        );
    }
}
