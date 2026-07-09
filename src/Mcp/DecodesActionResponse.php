<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Component\HttpFoundation\Response;

use function is_array;
use function is_string;
use function strip_tags;
use function trim;

/**
 * Shared decoding of a delegated controller's JSON response for MCP write tools.
 * The tracking controllers return an App\Model\JsonResponse on success and an
 * App\Response\Error (also a JsonResponse, `{message}`, 4xx) on failure.
 */
trait DecodesActionResponse
{
    /**
     * @return array<array-key, mixed>
     */
    private function decodeBody(Response $response): array
    {
        /** @var mixed $data */
        $data = json_decode((string) $response->getContent(), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<array-key, mixed> $body
     */
    private function errorMessage(array $body, string $fallback): string
    {
        $message = $body['message'] ?? null;

        return is_string($message) && '' !== $message ? $message : $fallback;
    }

    /**
     * Error text from a delegated response that may be JSON ({message}) OR a
     * plain-text body (the admin Save*Actions return translated text with a
     * 4xx status).
     */
    private function errorMessageFromResponse(Response $response, string $fallback): string
    {
        $message = $this->errorMessage($this->decodeBody($response), '');
        if ('' !== $message) {
            return $message;
        }

        $text = trim(strip_tags((string) $response->getContent()));

        return '' !== $text ? $text : $fallback;
    }
}
