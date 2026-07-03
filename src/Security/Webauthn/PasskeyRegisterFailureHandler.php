<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\Webauthn;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Webauthn\Bundle\Security\Handler\FailureHandler;

/**
 * Passkey REGISTRATION failed (ADR-018 D3). The bundle's default handler echoes
 * the raw exception message to the client — which can carry internals (ceremony
 * validation detail, ORM errors). Log the real cause server-side and answer with
 * a generic marker; the SPA shows its own localized message.
 */
final readonly class PasskeyRegisterFailureHandler implements FailureHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function onFailure(Request $request, ?Throwable $exception = null): JsonResponse
    {
        // PSR-3 context: the exception key must hold a Throwable, never null.
        $this->logger->error('Passkey registration failed.', $exception instanceof Throwable ? ['exception' => $exception] : []);

        return new JsonResponse(['success' => false, 'error' => 'passkey_registration_failed'], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
