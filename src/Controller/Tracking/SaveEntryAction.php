<?php

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Dto\EntrySaveDto;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\EntryClass;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\ActivityRepository;
use App\Repository\CustomerRepository;
use App\Repository\EntryRepository;
use App\Repository\ProjectRepository;
use App\Response\Error;
use BadMethodCallException;
use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

use function assert;
use function sprintf;

final class SaveEntryAction extends BaseTrackingController
{
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
        $customerRepo = $this->managerRegistry->getRepository(Customer::class);
        assert($customerRepo instanceof CustomerRepository);

        $customerId = $entrySaveDto->getCustomerId();
        if (null === $customerId) {
            return new JsonResponse(['error' => 'Customer ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $customer = $customerRepo->findOneById($customerId);

        if (!$customer instanceof Customer) {
            return new Error('Given customer does not exist.', Response::HTTP_BAD_REQUEST);
        }

        $projectRepo = $this->managerRegistry->getRepository(Project::class);
        assert($projectRepo instanceof ProjectRepository);

        $projectId = $entrySaveDto->getProjectId();
        if (null === $projectId) {
            return new JsonResponse(['error' => 'Project ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $project = $projectRepo->findOneById($projectId);

        if (!$project instanceof Project) {
            return new Error('Given project does not exist.', Response::HTTP_BAD_REQUEST);
        }

        $activityRepo = $this->managerRegistry->getRepository(Activity::class);
        assert($activityRepo instanceof ActivityRepository);

        $activityId = $entrySaveDto->getActivityId();
        if (null === $activityId) {
            return new JsonResponse(['error' => 'Activity ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $activity = $activityRepo->findOneById($activityId);

        if (!$activity instanceof Activity) {
            return new Error('Given activity does not exist.', Response::HTTP_BAD_REQUEST);
        }

        $entryId = $entrySaveDto->id;

        // Should we check if the ticket belongs to the project
        if ('' !== $entrySaveDto->ticket && '0' !== $entrySaveDto->ticket) {
            // Use project's jira_id as the expected prefix if it exists
            $prefix = $project->getJiraId();

            if (null !== $prefix && '' !== $prefix) {
                if (!str_starts_with($entrySaveDto->ticket, $prefix)) {
                    return new Error('Given ticket does not have a valid prefix.', Response::HTTP_BAD_REQUEST);
                }

                if (!str_contains($entrySaveDto->ticket, '-')) {
                    return new Error('Given ticket does not have a valid format.', Response::HTTP_BAD_REQUEST);
                }
            }
        }

        $entryRepo = $this->managerRegistry->getRepository(Entry::class);
        assert($entryRepo instanceof EntryRepository);

        $entry = null;
        if (null !== $entryId) {
            $entry = $entryRepo->findOneById($entryId);
        }

        // Check if someone else already owns the entry (if exists)
        if ($entry instanceof Entry && $entry->getUserId() !== $user->getId()) {
            return new Error('Entry is already owned by a different user.', Response::HTTP_BAD_REQUEST);
        }

        if (!$entry instanceof Entry) {
            $entry = new Entry();
        }

        $entry->setUser($user);
        $entry->setCustomer($customer);
        $entry->setProject($project);
        $entry->setActivity($activity);
        $entry->setClass(EntryClass::DAYBREAK);

        // Use DTO methods for date/time parsing (supports multiple formats)
        $dayDate = $entrySaveDto->getDateAsDateTime();
        if (null === $dayDate && '' !== $entrySaveDto->date && '0' !== $entrySaveDto->date) {
            return new Error('Given day does not have a valid format.', Response::HTTP_BAD_REQUEST);
        }
        if ($dayDate instanceof DateTimeInterface) {
            $entry->setDay(DateTime::createFromInterface($dayDate));
        }

        $startTime = $entrySaveDto->getStartAsDateTime();
        if (null === $startTime && '' !== $entrySaveDto->start && '0' !== $entrySaveDto->start) {
            return new Error('Given start does not have a valid format.', Response::HTTP_BAD_REQUEST);
        }
        if ($startTime instanceof DateTimeInterface) {
            $entry->setStart(DateTime::createFromInterface($startTime));
        }

        $endTime = $entrySaveDto->getEndAsDateTime();
        if (null === $endTime && '' !== $entrySaveDto->end && '0' !== $entrySaveDto->end) {
            return new Error('Given end does not have a valid format.', Response::HTTP_BAD_REQUEST);
        }
        if ($endTime instanceof DateTimeInterface) {
            $entry->setEnd(DateTime::createFromInterface($endTime));
        }

        if ('' !== $entrySaveDto->description && '0' !== $entrySaveDto->description) {
            $entry->setDescription($entrySaveDto->description);
        }

        if ('' !== $entrySaveDto->ticket && '0' !== $entrySaveDto->ticket) {
            $entry->setTicket($entrySaveDto->ticket);
        }

        if (!$project->getActive()) {
            return new Error('Project is no longer active.', Response::HTTP_BAD_REQUEST);
        }

        // Calculate duration
        if ($entry->getStart() instanceof DateTime && $entry->getEnd() instanceof DateTime) {
            $start = $entry->getStart();
            $end = $entry->getEnd();

            if ($start >= $end) {
                return new Error('Start time cannot be after end time.', Response::HTTP_BAD_REQUEST);
            }

            $interval = $start->diff($end);
            $hours = $interval->h;
            $minutes = $interval->i;

            // Convert to decimal hours with minutes as fractional part, then to minutes as integer
            $duration = (float) $hours + ((float) $minutes / 60.0);
            $entry->setDuration((int) round($duration * 60));
        }

        try {
            $entityManager = $this->managerRegistry->getManager();
            $entityManager->persist($entry);
            $entityManager->flush();

            // Return JSON response matching test expectations
            $day = $entry->getDay();
            $start = $entry->getStart();
            $end = $entry->getEnd();
            $user = $entry->getUser();
            $customer = $entry->getCustomer();
            $project = $entry->getProject();
            $activity = $entry->getActivity();

            if (!$day instanceof DateTime || !$start instanceof DateTime || !$end instanceof DateTime
                || !$user instanceof User || !$customer instanceof Customer
                || !$project instanceof Project || !$activity instanceof Activity) {
                return new Error('Entry data is incomplete.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $durationMinutes = $entry->getDuration();
            $durationString = sprintf('%02d:%02d', (int) ($durationMinutes / 60), $durationMinutes % 60);

            $data = [
                'result' => [
                    'id' => $entry->getId(),
                    'date' => $day->format('d/m/Y'),
                    'start' => $start->format('H:i'),
                    'end' => $end->format('H:i'),
                    'user' => $user->getId(),
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

            if ('' !== $entrySaveDto->description && '0' !== $entrySaveDto->description) {
                $data['result']['description'] = $entry->getDescription();
            }

            return new JsonResponse($data);
        } catch (Throwable $throwable) {
            return new Error('Could not save entry: ' . $throwable->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
