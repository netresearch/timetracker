<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Dto\EntrySaveDto;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Event\EntryEvent;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\EntryRepository;
use App\Repository\ProjectRepository;
use App\Response\Error;
use App\Service\Util\TicketService;
use BadMethodCallException;
use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

use function array_map;
use function assert;
use function in_array;
use function sprintf;

final class SaveEntryAction extends BaseTrackingController
{
    private ?EventDispatcherInterface $eventDispatcher = null;

    private TicketService $ticketService;

    #[Required]
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    #[Required]
    public function setTicketService(TicketService $ticketService): void
    {
        $this->ticketService = $ticketService;
    }

    /**
     * @throws BadRequestException
     * @throws BadMethodCallException
     * @throws InvalidArgumentException
     */
    #[Route(path: '/tracking/save', name: 'timetracking_save_attr', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        #[MapRequestPayload]
        EntrySaveDto $entrySaveDto,
        #[CurrentUser]
        User $user,
    ): Response|JsonResponse|Error {
        $customer = $this->resolveCustomer($entrySaveDto->getCustomerId());
        if (!$customer instanceof Customer) {
            return $customer;
        }

        $project = $this->resolveProject($entrySaveDto->getProjectId());
        if (!$project instanceof Project) {
            return $project;
        }

        $activity = $this->resolveActivity($entrySaveDto->getActivityId());
        if (!$activity instanceof Activity) {
            return $activity;
        }

        // v4 parity: tickets are stored upper-cased and trimmed
        $ticket = strtoupper(trim($entrySaveDto->ticket));

        // Should we check if the ticket belongs to the project
        $ticketError = $this->validateTicket($project, $ticket);
        if ($ticketError instanceof Error) {
            return $ticketError;
        }

        $entry = $this->findExistingEntry($entrySaveDto->id);

        // Check if someone else already owns the entry (if exists)
        if ($entry instanceof Entry && $entry->getUserId() !== $user->getId()) {
            return new Error($this->translate('Entry is already owned by a different user.'), Response::HTTP_BAD_REQUEST);
        }

        $isNewEntry = !$entry instanceof Entry;
        if (!$entry instanceof Entry) {
            $entry = new Entry();
        }

        // pre-mutation snapshot for the event subscriber (worklog cleanup on
        // ticket changes) and the day-class recalculation of a moved entry
        $previousEntry = $isNewEntry ? null : clone $entry;

        $populateError = $this->populateEntry($entry, $entrySaveDto, $user, $customer, $project, $activity, $ticket);
        if ($populateError instanceof Error) {
            return $populateError;
        }

        // Only block an inactive project when it is newly assigned or changed — an
        // existing entry that KEEPS its (since-deactivated) project must still save,
        // so editing its other fields (start/end/description) never fails. The UI
        // also hides inactive projects from the picker, so a fresh pick is active.
        $projectChanged = !$previousEntry instanceof Entry || $previousEntry->getProjectId() !== $project->getId();
        if ($projectChanged && !$project->getActive()) {
            return new Error($this->translate('Project is no longer active.'), Response::HTTP_BAD_REQUEST);
        }

        $durationError = $this->calculateDuration($entry);
        if ($durationError instanceof Error) {
            return $durationError;
        }

        try {
            $this->persistEntry($entry, $isNewEntry, $previousEntry);

            // v4 parity: recalculate the day-break/pause/overlap rendering
            // classes of the affected day(s)
            $this->recalculateDayClasses($entry, $previousEntry, $user);

            // Return JSON response matching test expectations
            return $this->buildEntryResponse($entry, $entrySaveDto);
        } catch (Throwable $throwable) {
            // Log the detail server-side; never leak a raw exception message (which
            // can carry SQL, paths or internals) into the user-facing API response.
            $this->logger->error('Failed to save a work-log entry.', [
                'error_type' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);

            return new Error($this->translate('Could not save the entry. Please try again.'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function resolveCustomer(?int $customerId): Customer|JsonResponse|Error
    {
        if (null === $customerId) {
            return new JsonResponse(['error' => 'Customer ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $customerRepo = $this->managerRegistry->getRepository(Customer::class);
        assert($customerRepo instanceof CustomerRepository);

        $customer = $customerRepo->findOneById($customerId);

        if (!$customer instanceof Customer) {
            return new Error($this->translate('Given customer does not exist.'), Response::HTTP_BAD_REQUEST);
        }

        return $customer;
    }

    private function resolveProject(?int $projectId): Project|JsonResponse|Error
    {
        if (null === $projectId) {
            return new JsonResponse(['error' => 'Project ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $projectRepo = $this->managerRegistry->getRepository(Project::class);
        assert($projectRepo instanceof ProjectRepository);

        $project = $projectRepo->findOneById($projectId);

        if (!$project instanceof Project) {
            return new Error($this->translate('Given project does not exist.'), Response::HTTP_BAD_REQUEST);
        }

        return $project;
    }

    private function resolveActivity(?int $activityId): Activity|JsonResponse|Error
    {
        if (null === $activityId) {
            return new JsonResponse(['error' => 'Activity ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $activityRepo = $this->managerRegistry->getRepository(Activity::class);
        assert($activityRepo instanceof ActivityRepository);

        $activity = $activityRepo->findOneById($activityId);

        if (!$activity instanceof Activity) {
            return new Error($this->translate('Given activity does not exist.'), Response::HTTP_BAD_REQUEST);
        }

        return $activity;
    }

    private function validateTicket(Project $project, string $ticket): ?Error
    {
        if ('' === $ticket || '0' === $ticket) {
            return null;
        }

        return $this->validateTicketPrefix($project, $ticket);
    }

    private function findExistingEntry(?int $entryId): ?Entry
    {
        if (null === $entryId) {
            return null;
        }

        $entryRepo = $this->managerRegistry->getRepository(Entry::class);
        assert($entryRepo instanceof EntryRepository);

        return $entryRepo->findOneById($entryId);
    }

    /**
     * Applies the request data to the entry; returns an error for invalid date/time formats.
     */
    private function populateEntry(
        Entry $entry,
        EntrySaveDto $entrySaveDto,
        User $user,
        Customer $customer,
        Project $project,
        Activity $activity,
        string $ticket,
    ): ?Error {
        $entry->setUser($user);
        $entry->setCustomer($customer);
        $entry->setProject($project);
        $entry->setActivity($activity);

        // Use DTO methods for date/time parsing (supports multiple formats)
        $dateError = $this->applyDay($entry, $entrySaveDto)
            ?? $this->applyStart($entry, $entrySaveDto)
            ?? $this->applyEnd($entry, $entrySaveDto);
        if ($dateError instanceof Error) {
            return $dateError;
        }

        // Always reflect the submitted state so clearing a description or ticket
        // inline actually persists. The grid POSTs the whole record
        // (savePayload), sending '' for a cleared field — the previous
        // non-empty guard made the cleared value silently revert on the next
        // refetch.
        $entry->setDescription($entrySaveDto->description);
        $entry->setTicket($ticket);

        // v4 parity: the UI round-trips the original external ticket key of
        // mirrored entries in "extTicket" (see internal Jira ticket system)
        $entry->setInternalJiraTicketOriginalKey(
            '' !== $entrySaveDto->extTicket ? $entrySaveDto->extTicket : null,
        );

        return null;
    }

    private function applyDay(Entry $entry, EntrySaveDto $entrySaveDto): ?Error
    {
        $dayDate = $entrySaveDto->getDateAsDateTime();
        if (!$dayDate instanceof DateTimeInterface && '' !== $entrySaveDto->date && '0' !== $entrySaveDto->date) {
            return new Error($this->translate('Given day does not have a valid format.'), Response::HTTP_BAD_REQUEST);
        }

        if ($dayDate instanceof DateTimeInterface) {
            $entry->setDay(DateTime::createFromInterface($dayDate));
        }

        return null;
    }

    private function applyStart(Entry $entry, EntrySaveDto $entrySaveDto): ?Error
    {
        $startTime = $entrySaveDto->getStartAsDateTime();
        if (!$startTime instanceof DateTimeInterface && '' !== $entrySaveDto->start && '0' !== $entrySaveDto->start) {
            return new Error($this->translate('Given start does not have a valid format.'), Response::HTTP_BAD_REQUEST);
        }

        if ($startTime instanceof DateTimeInterface) {
            $entry->setStart(DateTime::createFromInterface($startTime));
        }

        return null;
    }

    private function applyEnd(Entry $entry, EntrySaveDto $entrySaveDto): ?Error
    {
        $endTime = $entrySaveDto->getEndAsDateTime();
        if (!$endTime instanceof DateTimeInterface && '' !== $entrySaveDto->end && '0' !== $entrySaveDto->end) {
            return new Error($this->translate('Given end does not have a valid format.'), Response::HTTP_BAD_REQUEST);
        }

        if ($endTime instanceof DateTimeInterface) {
            $entry->setEnd(DateTime::createFromInterface($endTime));
        }

        return null;
    }

    /**
     * Calculates and sets the entry duration; returns an error when start is not before end.
     */
    private function calculateDuration(Entry $entry): ?Error
    {
        $start = $entry->getStart();
        $end = $entry->getEnd();

        if (!$start instanceof DateTime || !$end instanceof DateTime) {
            return null;
        }

        if ($start >= $end) {
            return new Error($this->translate('Start time cannot be after end time.'), Response::HTTP_BAD_REQUEST);
        }

        $interval = $start->diff($end);
        $hours = $interval->h;
        $minutes = $interval->i;

        // Convert to decimal hours with minutes as fractional part, then to minutes as integer
        $duration = (float) $hours + ((float) $minutes / 60.0);
        $entry->setDuration((int) round($duration * 60));

        return null;
    }

    private function persistEntry(Entry $entry, bool $isNewEntry, ?Entry $previousEntry): void
    {
        $entityManager = $this->managerRegistry->getManager();
        $entityManager->persist($entry);
        $entityManager->flush();

        // Dispatch entry event for Jira sync and cache invalidation
        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            $eventName = $isNewEntry ? EntryEvent::CREATED : EntryEvent::UPDATED;
            $this->eventDispatcher->dispatch(
                new EntryEvent($entry, ['previous' => $previousEntry]),
                $eventName,
            );
        }
    }

    private function recalculateDayClasses(Entry $entry, ?Entry $previousEntry, User $user): void
    {
        $entryDay = $entry->getDay();
        if (!$entryDay instanceof DateTime) {
            return;
        }

        $this->calculateClasses($user->getId() ?? 0, $entryDay->format('Y-m-d'));

        $previousDay = $previousEntry?->getDay();
        if ($previousDay instanceof DateTime
            && $previousDay->format('Y-m-d') !== $entryDay->format('Y-m-d')
        ) {
            $this->calculateClasses($user->getId() ?? 0, $previousDay->format('Y-m-d'));
        }
    }

    private function buildEntryResponse(Entry $entry, EntrySaveDto $entrySaveDto): JsonResponse|Error
    {
        $day = $entry->getDay();
        $start = $entry->getStart();
        $end = $entry->getEnd();
        $entryUser = $entry->getUser();
        $customer = $entry->getCustomer();
        $project = $entry->getProject();
        $activity = $entry->getActivity();

        if (!$day instanceof DateTime || !$start instanceof DateTime || !$end instanceof DateTime
            || !$entryUser instanceof User || !$customer instanceof Customer
            || !$project instanceof Project || !$activity instanceof Activity) {
            return new Error($this->translate('Entry data is incomplete.'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $durationMinutes = $entry->getDuration();
        $durationString = sprintf('%02d:%02d', (int) ($durationMinutes / 60), $durationMinutes % 60);

        $data = [
            'result' => [
                'id' => $entry->getId(),
                'date' => $day->format('d/m/Y'),
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
                'user' => $entryUser->getId(),
                'customer' => $customer->getId(),
                'project' => $project->getId(),
                'activity' => $activity->getId(),
                'duration' => $durationString,
                'durationMinutes' => $durationMinutes,
                'class' => $entry->getClass()->value,
            ],
        ];

        // Include ticket and description if present
        if ('' !== $entrySaveDto->ticket && '0' !== $entrySaveDto->ticket) {
            $data['result']['ticket'] = $entry->getTicket();
        }

        // The UI round-trips the original external ticket key of
        // mirrored entries from this field (v4: part of toArray())
        if ($entry->hasInternalJiraTicketOriginalKey()) {
            $data['result']['extTicket'] = $entry->getInternalJiraTicketOriginalKey();
        }

        if ('' !== $entrySaveDto->description && '0' !== $entrySaveDto->description) {
            $data['result']['description'] = $entry->getDescription();
        }

        return new JsonResponse($data);
    }

    /**
     * TTT-199: check if the ticket prefix matches the project's Jira ID.
     *
     * v4 parity: the project's jira_id is a comma-separated list of allowed
     * prefixes (entries are trimmed before comparison), and the project's
     * internal Jira project key is accepted as an alternative match.
     */
    private function validateTicketPrefix(Project $project, string $ticket): ?Error
    {
        $jiraId = $project->getJiraId();
        if (null === $jiraId || '' === $jiraId) {
            return null;
        }

        if (!$this->ticketService->checkFormat($ticket)) {
            return new Error($this->translate('Given ticket does not have a valid format.'), Response::HTTP_BAD_REQUEST);
        }

        // A ticket explicitly listed in the project's synced subtickets is accepted
        // regardless of its prefix: subtickets can live in a different Jira project
        // (e.g. epic-linked issues), so an exact key match wins over the prefix rule.
        if ($this->isKnownSubticket($project, $ticket)) {
            return null;
        }

        $ticketPrefix = (string) $this->ticketService->getPrefix($ticket);
        $allowedPrefixes = array_map(trim(...), explode(',', $jiraId));
        $prefixMatches = in_array($ticketPrefix, $allowedPrefixes, true)
            || $project->matchesInternalJiraProject($ticketPrefix);

        return $prefixMatches
            ? null
            : new Error($this->translate('Given ticket does not have a valid prefix.'), Response::HTTP_BAD_REQUEST);
    }

    /**
     * Whether the ticket key is one of the project's synced subtickets (exact,
     * case-insensitive match). Empty subtickets → never matches.
     */
    private function isKnownSubticket(Project $project, string $ticket): bool
    {
        $subtickets = $project->getSubtickets();
        if (null === $subtickets || '' === $subtickets) {
            return false;
        }

        $needle = strtoupper(trim($ticket));
        foreach (explode(',', $subtickets) as $subticket) {
            if (strtoupper(trim($subticket)) === $needle) {
                return true;
            }
        }

        return false;
    }
}
