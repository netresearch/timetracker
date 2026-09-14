<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Entity\Entry;
use App\Entity\User;
use App\Event\EntryEvent;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Security\ApiToken\RequireScope;
use App\Util\RequestEntityHelper;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\Attribute\Required;

final class DeleteEntryAction extends BaseTrackingController
{
    private ?EventDispatcherInterface $eventDispatcher = null;

    #[Required]
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @throws BadRequestException When request parameters are invalid
     */
    #[RequireScope('entries:write')]
    #[Route(path: '/tracking/delete', name: 'timetracking_delete_attr', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $currentUser,
    ): Response|JsonResponse|Error {
        $entry = $this->resolveEntry($request);
        if ($entry instanceof Error) {
            return $entry;
        }

        if (!$this->mayDelete($entry, $currentUser)) {
            return new Error(
                $this->translator->trans('You are not allowed to delete this entry.'),
                \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN,
            );
        }

        // ADR-025: an agent entry and its delegated human estimate are one logged
        // session — deleting one half deletes both, so no orphan half survives. The
        // partner goes through the same ownership check as the requested entry.
        $toDelete = [$entry];
        $partner = $entry->getPairedEntry();
        if ($partner instanceof Entry && $this->mayDelete($partner, $currentUser)) {
            $toDelete[] = $partner;
        }

        // The owners' days are what changed — recalculate their classes, not the
        // deleter's (an admin/PL may be removing someone else's entry).
        $affectedDays = [];
        foreach ($toDelete as $doomed) {
            $ownerId = $doomed->getUserId() ?? 0;
            $day = $doomed->getDay()->format('Y-m-d');
            $affectedDays[$ownerId . '|' . $day] = [$ownerId, $day];
        }

        // Dispatch events before removal (subscriber handles Jira worklog deletion)
        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            foreach ($toDelete as $doomed) {
                $this->eventDispatcher->dispatch(new EntryEvent($doomed), EntryEvent::DELETED);
            }
        }

        $deletedIds = array_map(static fn (Entry $doomed): ?int => $doomed->getId(), $toDelete);

        $manager = $this->managerRegistry->getManager();
        foreach ($toDelete as $doomed) {
            $manager->remove($doomed);
        }
        $manager->flush();

        foreach ($affectedDays as [$ownerId, $day]) {
            $this->calculateClasses($ownerId, $day);
        }

        return new JsonResponse(['success' => true, 'deleted' => $deletedIds]);
    }

    /**
     * Resolve the entry to delete from the request, reading the id from the merged
     * payload so form (SPA) and JSON (API/token) clients behave identically. A
     * missing/invalid id is a client error, not a silent success — the old code
     * returned {"success":true} without deleting anything when the id could not be
     * read (e.g. from a JSON body).
     */
    private function resolveEntry(Request $request): Entry|Error
    {
        $entryId = (int) $request->getPayload()->get('id', 0);
        if ($entryId <= 0) {
            return new Error(
                $this->translator->trans('No entry id provided.'),
                \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST,
            );
        }

        $entry = RequestEntityHelper::findById($this->managerRegistry, Entry::class, (string) $entryId);

        return $entry instanceof Entry ? $entry : new Error(
            $this->translator->trans('No entry for id.'),
            \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
        );
    }

    /**
     * Ownership (mirrors GetEntryAction): a developer may only delete their own
     * entries; admins and project leads (ROLE_ADMIN — PL carries it) may delete any.
     * Without this, any authenticated principal — including an entries:write API
     * token — could delete another user's entry by id.
     */
    private function mayDelete(Entry $entry, User $currentUser): bool
    {
        if ($entry->getUserId() === $currentUser->getId()) {
            return true;
        }
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return $currentUser->getType()->isPl();
    }
}
