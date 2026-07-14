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

        // The owner's day is what changed — recalculate their classes, not the
        // deleter's (an admin/PL may be removing someone else's entry).
        $ownerId = $entry->getUserId() ?? 0;
        $day = $entry->getDay()->format('Y-m-d');

        // Dispatch event before removal (subscriber handles Jira worklog deletion)
        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            $this->eventDispatcher->dispatch(new EntryEvent($entry), EntryEvent::DELETED);
        }

        $manager = $this->managerRegistry->getManager();
        $manager->remove($entry);
        $manager->flush();

        $this->calculateClasses($ownerId, $day);

        return new JsonResponse(['success' => true]);
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
