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
     * @param array<string, string>|array<string, array<string>> $headers
     */
    public function __construct(mixed $content = null, int $status = 200, array $headers = [])
    {
        parent::__construct('', $status, $headers);
        
        if (null !== $content) {
            $encoded = is_string($content) ? json_encode($content) : json_encode($content);
            parent::setContent(false !== $encoded ? (string) $encoded : 'null');
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
