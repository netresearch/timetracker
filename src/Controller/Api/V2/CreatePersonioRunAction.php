<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\SyncRunDto;
use App\Entity\SyncRun;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use App\Service\Personio\PersonioRunTrigger;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

use function array_map;
use function is_array;
use function is_string;
use function json_decode;

/**
 * Start a Personio run on demand (ADR-024 P3 trigger). The body's `direction`
 * selects `export` (attendances, TT → Personio) or `import` (absences, Personio
 * → TT). It runs for the caller by default; `all_users` runs the cron path for
 * every opted-in, mapped user and requires ROLE_ADMIN. Runs execute inline —
 * the response carries the finished run(s) with counters and parked items.
 */
final readonly class CreatePersonioRunAction
{
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private PersonioRunTrigger $personioRunTrigger,
    ) {
    }

    #[RequireScope('sync:write')]
    #[Route(path: '/api/v2/personio/runs', name: 'api_v2_personio_run_create', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $content = $request->getContent();
        $payload = json_decode('' !== $content ? $content : '{}', true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $direction = is_string($payload['direction'] ?? null) ? $payload['direction'] : '';
        if (!PersonioRunTrigger::isDirection($direction)) {
            return new JsonResponse(['message' => 'direction must be "export" or "import".'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $allUsers = true === ($payload['all_users'] ?? false);
        if ($allUsers && !$this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['message' => 'Admin role required to run for all users.'], Response::HTTP_FORBIDDEN);
        }

        $from = is_string($payload['from'] ?? null) ? $payload['from'] : null;
        $to = is_string($payload['to'] ?? null) ? $payload['to'] : null;
        $dryRun = true === ($payload['dry_run'] ?? false);

        try {
            $runs = $this->personioRunTrigger->run($direction, $user, $allUsers, $from, $to, $dryRun);
        } catch (Exception) {
            return new JsonResponse(['message' => 'Invalid date in from/to (expected Y-m-d).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(
            ['runs' => array_map(static fn (SyncRun $syncRun): SyncRunDto => SyncRunDto::fromEntity($syncRun), $runs)],
            Response::HTTP_CREATED,
        );
    }
}
