<?php

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
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */

namespace App\Model;

use InvalidArgumentException;
use JsonException;
use Override;

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
     *
     * @throws JsonException            When JSON encoding fails
     * @throws InvalidArgumentException When content cannot be encoded
     */
    public function __construct(mixed $content = null, int $status = 200, array $headers = [])
    {
        // Encode content first to ensure we have a string for parent constructor
        $encoded = match ($content) {
            null => 'null',
            default => json_encode($content) ?: 'null',
        };

        // Initialize base Response with proper content - this resolves PropertyNotSetInConstructor
        parent::__construct($encoded, $status, $headers);

        // Set additional properties that may be missing from parent initialization
        $this->headers->set('Content-Type', 'application/json');
    }

    /**
     * Add additional headers before sending an JSON reply to the client.
     */
    #[Override]
    public function send(bool $flush = true): static
    {
        // Ensure Content-Type is always set for JSON responses
        if (!$this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', 'application/json');
        }

        parent::send($flush);

        return $this;
    }
}
