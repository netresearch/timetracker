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
}
