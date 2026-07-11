<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Ui;

use App\Controller\BaseController;
use App\Entity\PersonioConfig;
use App\Entity\User;
use App\Repository\PersonioConfigRepository;
use App\Service\Security\TwoFactorStatusService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

/**
 * Serves the new SolidJS single-page UI (frontend/) for every /ui/* path.
 *
 * The SPA shell must be a Symfony route (not a static file in public/) so the
 * firewall's catch-all access control applies; client-side routing handles the
 * wildcard rest of the path.
 */
final class SpaAction extends BaseController
{
    public function __construct(
        private readonly TwoFactorStatusService $twoFactorStatus,
        private readonly PersonioConfigRepository $personioConfigRepository,
    ) {
    }

    /**
     * @throws LoaderError  When template loading fails
     * @throws RuntimeError When template rendering fails
     * @throws SyntaxError  When template syntax is invalid
     */
    #[Route(path: '/ui/{path}', name: 'ui_spa', requirements: ['path' => '.*'], defaults: ['path' => ''], methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user = null): RedirectResponse|Response
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $settings = $user->getSettings();

        return $this->render('ui/index.html.twig', [
            'globalConfig' => [
                'logo_url' => $this->params->get('app_logo_url'),
                'header_url' => $this->params->get('app_header_url'),
            ],
            'apptitle' => $this->params->get('app_title'),
            'locale' => $settings['locale'],
            'settings' => $settings,
            // Org-wide mandatory 2FA (ADR-018): the flag plus this user's current
            // 2FA state (TOTP OR passkey) drive the SPA's enrolment gate.
            'twoFactorRequired' => (bool) $this->params->get('app_require_two_factor'),
            'hasTwoFactor' => $this->twoFactorStatus->hasTwoFactor($user),
            // Whether an active admin-side Personio config exists; the SPA greys out
            // the per-user attendance opt-in when Personio isn't configured (ADR-024).
            'personioConfigured' => $this->personioConfigRepository->findActive() instanceof PersonioConfig,
        ]);
    }
}
