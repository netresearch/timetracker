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
use App\Response\Error;
use BadMethodCallException;
use DateTime;
use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Throwable;

use function sprintf;

final class SaveEntryAction extends BaseTrackingController
{
    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException
     * @throws BadMethodCallException
     * @throws InvalidArgumentException
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/save', name: 'timetracking_save_attr', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload]
        EntrySaveDto $dto,
    ): Response|JsonResponse|Error|RedirectResponse {
        if (!$this->checkLogin($request)) {
            return $this->redirectToRoute('_login');
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->managerRegistry->getRepository(User::class);

        $user = $userRepo->findOneById($userId);

        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        /** @var \App\Repository\CustomerRepository $customerRepo */
        $customerRepo = $this->managerRegistry->getRepository(Customer::class);

        $customerId = $dto->getCustomerId();
        if (null === $customerId) {
            return new JsonResponse(['error' => 'Customer ID is required'], Response::HTTP_BAD_REQUEST);
        }
        $customer = $customerRepo->findOneById($customerId);

        if (!$customer instanceof Customer) {
            return new Error('Given customer does not exist.', Response::HTTP_BAD_REQUEST);
        }

        /** @var \App\Repository\ProjectRepository $projectRepo */
        $projectRepo = $this->managerRegistry->getRepository(Project::class);

        $projectId = $dto->getProjectId();
        if (null === $projectId) {
            return new JsonResponse(['error' => 'Project ID is required'], Response::HTTP_BAD_REQUEST);
        }
        $project = $projectRepo->findOneById($projectId);

        if (!$project instanceof Project) {
            return new Error('Given project does not exist.', Response::HTTP_BAD_REQUEST);
        }

        /** @var \App\Repository\ActivityRepository $activityRepo */
        $activityRepo = $this->managerRegistry->getRepository(Activity::class);

        $activityId = $dto->getActivityId();
        if (null === $activityId) {
            return new JsonResponse(['error' => 'Activity ID is required'], Response::HTTP_BAD_REQUEST);
        }
        $activity = $activityRepo->findOneById($activityId);

        if (!$activity instanceof Activity) {
            return new Error('Given activity does not exist.', Response::HTTP_BAD_REQUEST);
        }

        $entryId = $dto->id;

        // Should we check if the ticket belongs to the project
        if (!empty($dto->ticket)) {
            // Use project's jira_id as the expected prefix if it exists
            $prefix = $project->getJiraId();

            if (null !== $prefix && '' !== $prefix) {
                if (!str_starts_with($dto->ticket, $prefix)) {
                    return new Error('Given ticket does not have a valid prefix.', Response::HTTP_BAD_REQUEST);
                }

                if (!str_contains($dto->ticket, '-')) {
                    return new Error('Given ticket does not have a valid format.', Response::HTTP_BAD_REQUEST);
                }
            }
        }

        /** @var \App\Repository\EntryRepository $entryRepo */
        $entryRepo = $this->managerRegistry->getRepository(Entry::class);

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

        try {
            if (!empty($dto->date)) {
                $dayDate = new DateTime($dto->date);
                $entry->setDay($dayDate);
            }
        } catch (Exception $exception) {
            return new Error('Given day does not have a valid format.', Response::HTTP_BAD_REQUEST);
        }

        try {
            if (!empty($dto->start)) {
                $startTime = new DateTime($dto->start);
                $entry->setStart($startTime);
            }
        } catch (Exception $exception) {
            return new Error('Given start does not have a valid format.', Response::HTTP_BAD_REQUEST);
        }

        try {
            if (!empty($dto->end)) {
                $endTime = new DateTime($dto->end);
                $entry->setEnd($endTime);
            }
        } catch (Exception $exception) {
            return new Error('Given end does not have a valid format.', Response::HTTP_BAD_REQUEST);
        }

        if (!empty($dto->description)) {
            $entry->setDescription($dto->description);
        }

        if (!empty($dto->ticket)) {
            $entry->setTicket($dto->ticket);
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

            $data = [
                'result' => [
                    'date' => $day->format('d/m/Y'),
                    'start' => $start->format('H:i'),
                    'end' => $end->format('H:i'),
                    'user' => $user->getId(),
                    'customer' => $customer->getId(),
                    'project' => $project->getId(),
                    'activity' => $activity->getId(),
                    'duration' => $entry->getDuration(),
                    'durationString' => sprintf('%02d:%02d', (int) ($entry->getDuration() / 60), $entry->getDuration() % 60),
                    'class' => $entry->getClass()->value,
                ],
            ];

            // Include ticket and description if present
            if (!empty($dto->ticket)) {
                $data['result']['ticket'] = $entry->getTicket();
            }
            if (!empty($dto->description)) {
                $data['result']['description'] = $entry->getDescription();
            }

            return new JsonResponse($data);
        } catch (Throwable $exception) {
            return new Error('Could not save entry: ' . $exception->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
