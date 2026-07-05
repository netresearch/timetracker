<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\BaseController;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Service\ApiToken\ApiTokenService;
use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;
use function is_string;
use function preg_match;
use function trim;

/**
 * Mint an API token for the current user (ADR-021 Phase 3). The plaintext secret is
 * returned exactly once, in this response — it is never recoverable afterwards.
 * Session-only (no #[RequireScope]): a Bearer token cannot mint further tokens.
 */
final class ApiTokenCreateAction extends BaseController
{
    /** The `name` column is length 100 (see the ApiToken entity). */
    private const int NAME_MAX_LENGTH = 100;

    public function __construct(private readonly ApiTokenService $apiTokens)
    {
    }

    #[Route(path: '/settings/api-tokens/create', name: 'settings_api_tokens_create', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $payload = $request->getPayload();

        $name = trim($payload->getString('name'));
        if ('' === $name || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return $this->unprocessable($this->translate('Please enter a token name (up to 100 characters).'));
        }

        // Read the whole payload rather than $payload->all('scopes'): the latter
        // throws BadRequestException (400) when a malformed request sends a scalar
        // for `scopes`, bypassing the 422 the empty-scope path below returns.
        $rawScopes = $payload->all()['scopes'] ?? [];
        /** @var list<string> $scopes */
        $scopes = is_array($rawScopes) ? array_values(array_filter($rawScopes, is_string(...))) : [];

        try {
            $expiresAt = $this->parseExpiry($payload->get('expiresAt'));
        } catch (DateMalformedStringException) {
            return $this->unprocessable($this->translate('The expiry date is not valid.'));
        }

        try {
            [$token, $plaintext] = $this->apiTokens->create($user, $name, $scopes, $expiresAt);
        } catch (InvalidArgumentException) {
            return $this->unprocessable($this->translate('Please select at least one valid scope.'));
        }

        $response = new JsonResponse([
            // Shown once — the client must copy it now; only the hash is stored.
            'token' => $plaintext,
            'id' => $token->getId(),
            'name' => $token->getName(),
            'scopes' => $token->getScopes(),
            'createdAt' => $token->getCreatedAt()->format(DateTimeInterface::ATOM),
            'expiresAt' => $token->getExpiresAt()?->format(DateTimeInterface::ATOM),
        ]);
        $response->setStatusCode(Response::HTTP_CREATED);

        return $response;
    }

    /**
     * A date-only value ("2026-12-31") is taken as end-of-that-day, so a token set to
     * expire on a date stays valid through it (isActive uses expiresAt > now).
     *
     * @throws DateMalformedStringException on an unparseable non-empty value
     */
    private function parseExpiry(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        $value = trim($value);
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value .= 'T23:59:59';
        }

        return new DateTimeImmutable($value);
    }

    private function unprocessable(string $message): JsonResponse
    {
        $response = new JsonResponse(['success' => false, 'message' => $message]);
        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        return $response;
    }
}
