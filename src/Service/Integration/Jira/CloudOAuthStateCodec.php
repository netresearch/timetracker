<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Exception\Integration\Jira\JiraApiException;
use App\Service\ClockInterface;
use App\Service\Security\TokenEncryptionService;
use Exception;
use JsonException;
use SensitiveParameter;

use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

/**
 * Encodes/decodes the OAuth 2.0 `state` parameter for the Jira Cloud flow.
 *
 * Atlassian requires the registered redirect URI to match exactly, so the
 * ticket-system id cannot ride along as a query parameter (as the OAuth 1.0a
 * flow does with `?tsid=`). Instead it is carried inside `state`, encrypted
 * with the application key so it is tamper-proof, bound to the initiating
 * user, and expiring after a short TTL.
 */
final readonly class CloudOAuthStateCodec
{
    private const int TTL_SECONDS = 600;

    public function __construct(
        private TokenEncryptionService $tokenEncryptionService,
        private ClockInterface $clock,
    ) {
    }

    public function encode(int $userId, int $ticketSystemId): string
    {
        $payload = json_encode([
            'u' => $userId,
            't' => $ticketSystemId,
            'e' => $this->clock->now()->getTimestamp() + self::TTL_SECONDS,
            'n' => bin2hex(random_bytes(8)),
        ], JSON_THROW_ON_ERROR);

        return $this->tokenEncryptionService->encryptToken($payload);
    }

    /**
     * @throws JiraApiException When the state is invalid, tampered with, or expired
     *
     * @return array{userId: int, ticketSystemId: int}
     */
    public function decode(#[SensitiveParameter] string $state): array
    {
        try {
            $payload = $this->tokenEncryptionService->decryptToken($state);
            $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException|Exception $exception) {
            throw new JiraApiException('Invalid OAuth state parameter.', 400, null, $exception);
        }

        if (!is_array($data) || !is_int($data['u'] ?? null) || !is_int($data['t'] ?? null) || !is_int($data['e'] ?? null)) {
            throw new JiraApiException('Invalid OAuth state parameter.', 400);
        }

        if ($this->clock->now()->getTimestamp() > $data['e']) {
            throw new JiraApiException('The OAuth state has expired — please restart the authorization.', 400);
        }

        return [
            'userId' => $data['u'],
            'ticketSystemId' => $data['t'],
        ];
    }
}
