<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Api\V2;

use App\Dto\Response\SyncRunDto;
use App\Dto\WorklogSyncRunDto;
use App\Entity\SyncRun;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Security\ApiToken\RequireScope;
use App\Service\Sync\ImportWorklogsService;
use App\Service\Sync\SyncWorklogsService;
use App\Service\Sync\VerifyWorklogsService;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

use function ctype_digit;

/**
 * Start a worklog verify/import/sync run against a ticket system (ADR-023
 * §6). Runs execute inline — the response carries the finished run with its
 * counters and findings. Non-admins may verify themselves and import only
 * their own worklogs; sync runs require the admin role.
 */
final readonly class CreateWorklogSyncRunAction
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private AuthorizationCheckerInterface $authorizationChecker,
        private VerifyWorklogsService $verifyWorklogsService,
        private ImportWorklogsService $importWorklogsService,
        private SyncWorklogsService $syncWorklogsService,
    ) {
    }

    #[RequireScope('sync:write')]
    #[Route(path: '/api/v2/worklog-sync/runs', name: 'api_v2_worklog_sync_run_create', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] WorklogSyncRunDto $worklogSyncRunDto, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($worklogSyncRunDto->ticket_system_id);
        if (!$ticketSystem instanceof TicketSystem) {
            return new JsonResponse(['message' => 'Ticket system not found.'], Response::HTTP_NOT_FOUND);
        }

        $denied = $this->authorize($worklogSyncRunDto, $user);
        if ($denied instanceof JsonResponse) {
            return $denied;
        }

        try {
            [$from, $to] = $this->parseRange($worklogSyncRunDto);
            $sinceMillis = $this->parseSince($worklogSyncRunDto);
        } catch (Exception) {
            return new JsonResponse(['message' => 'Invalid date in from/to/since (expected Y-m-d).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ('import' === $worklogSyncRunDto->type && null === $worklogSyncRunDto->default_activity_id) {
            return new JsonResponse(['message' => 'default_activity_id is required for import runs.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $syncRun = $this->dispatch($worklogSyncRunDto, $user, $ticketSystem, $from, $to, $sinceMillis);

        return new JsonResponse(SyncRunDto::fromEntity($syncRun), Response::HTTP_CREATED);
    }

    /**
     * The authorization matrix (ADR-023 §6): admins may start anything;
     * non-admins may verify themselves, import only their own username, and
     * never sync.
     */
    private function authorize(WorklogSyncRunDto $worklogSyncRunDto, User $user): ?JsonResponse
    {
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return null;
        }

        if ('sync' === $worklogSyncRunDto->type) {
            return new JsonResponse(['message' => 'Admin role required for sync runs.'], Response::HTTP_FORBIDDEN);
        }

        if ('import' === $worklogSyncRunDto->type && [$user->getUsername()] !== $worklogSyncRunDto->users) {
            return new JsonResponse(['message' => 'Non-admin imports are limited to your own user.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @throws Exception on malformed dates
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    private function parseRange(WorklogSyncRunDto $worklogSyncRunDto): array
    {
        $from = null !== $worklogSyncRunDto->from ? new DateTimeImmutable($worklogSyncRunDto->from) : new DateTimeImmutable('first day of this month');
        $to = null !== $worklogSyncRunDto->to ? new DateTimeImmutable($worklogSyncRunDto->to) : new DateTimeImmutable('today');

        return [$from, $to];
    }

    /**
     * Cursor override for sync runs — Y-m-d or epoch milliseconds, like the
     * tt:sync-worklogs command's --since option.
     *
     * @throws Exception on a malformed date string
     */
    private function parseSince(WorklogSyncRunDto $worklogSyncRunDto): ?int
    {
        if ('sync' !== $worklogSyncRunDto->type || null === $worklogSyncRunDto->since) {
            return null;
        }

        if (ctype_digit($worklogSyncRunDto->since)) {
            return (int) $worklogSyncRunDto->since;
        }

        return new DateTimeImmutable($worklogSyncRunDto->since)->getTimestamp() * 1000;
    }

    private function dispatch(
        WorklogSyncRunDto $worklogSyncRunDto,
        User $user,
        TicketSystem $ticketSystem,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $sinceMillis,
    ): SyncRun {
        return match ($worklogSyncRunDto->type) {
            'verify' => $this->verifyWorklogsService->verify($user, $ticketSystem, $from, $to),
            'import' => $this->importWorklogsService->import(
                $user,
                $ticketSystem,
                $from,
                $to,
                (int) $worklogSyncRunDto->default_activity_id,
                $worklogSyncRunDto->users,
                $worklogSyncRunDto->dry_run,
            ),
            default => $this->syncWorklogsService->sync($ticketSystem, $sinceMillis, $worklogSyncRunDto->dry_run),
        };
    }
}
