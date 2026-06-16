<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Ui;

use App\Controller\BaseController;
use App\Entity\User;
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
        ]);
    }
}
