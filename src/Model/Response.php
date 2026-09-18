<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

/**
 * Netresearch Timetracker.
 *
 * PHP version 5
 *
 * @category   Netresearch
 *
 * @author     Various Artists
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */

namespace App\Model;

/**
 * Class Response.
 *
 * Marker type for the JSON/AJAX replies of the controller actions.
 *
 * Until 2026-09 this overrode send() to add `Access-Control-Allow-Origin: *`
 * (php:S5122). The SPA is served from the same origin as the API and no
 * cross-origin client is documented, so the wildcard only widened the reach of
 * every unauthenticated response. Cross-origin access, if it is ever needed,
 * belongs in a CORS configuration with an explicit origin list, not here.
 *
 * @category   Netresearch
 *
 * @author     Various Artists
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */
class Response extends \Symfony\Component\HttpFoundation\Response
{
}
