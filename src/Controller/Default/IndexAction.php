<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The root route redirects into the SolidJS SPA.
 * Kept as the named `_start` route so every "go home" flow (login
 * success, OAuth callback, the 403 page, the Jira time-summary macro) funnels
 * to the worklog at /ui/tracking. Anonymous requests never reach here: the
 * firewall redirects `/` to the login page first (access_control ^/).
 */
final class IndexAction extends BaseController
{
    #[Route(path: '/', name: '_start', methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return $this->redirectToRoute('ui_spa', ['path' => 'tracking']);
    }
}
