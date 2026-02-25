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

use Override;

/**
 * Class Response.
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
    /**
     * Add additional headers before sending an ajax reply to the client.
     */
    #[Override]
    public function send(bool $flush = true): static
    {
        $this->headers->set('Access-Control-Allow-Origin', '*');
        $this->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $this->headers->set('Access-Control-Max-Age', '3600');

        parent::send($flush);

        return $this;
    }
}
