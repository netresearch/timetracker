<?php

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
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */

namespace App\Model;

/**
 * JSON response.
 *
 * @category   Netresearch
 *
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */
class JsonResponse extends Response
{
    /**
     * @param array<string, string|array<string>> $headers
     * @psalm-suppress PropertyNotSetInConstructor
     */
    public function __construct(mixed $content = null, int $status = 200, array $headers = [])
    {
        // Initialize base Response with sane defaults
        parent::__construct('', $status, $headers);
        $this->version = '1.1';
        $this->statusText = '';
        $this->charset = 'UTF-8';

        if (null !== $content) {
            $encoded = json_encode($content);
            parent::setContent(false !== $encoded ? $encoded : 'null');
        } else {
            parent::setContent('null');
        }
    }

    /**
     * Add additional headers before sending an JSON reply to the client.
     */
    public function send(): static
    {
        $this->headers->set('Content-Type', 'application/json');
        parent::send();

        return $this;
    }
}
