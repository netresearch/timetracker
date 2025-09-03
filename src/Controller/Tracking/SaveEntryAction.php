<?php

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Dto\EntrySaveDto;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\UserType;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Util\RequestEntityHelper;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Throwable;

final class SaveEntryAction extends BaseTrackingController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/save', name: 'timetracking_save_attr', methods: ['POST'])]
    public function __invoke(
        Request $request,
        #[MapRequestPayload] EntrySaveDto $dto,
    ): Response|JsonResponse|Error {
        if (!$this->checkLogin($request)) {
            return $this->redirectToRoute('_login');
        }

        $userId = $this->getUserId($request);
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = $this->managerRegistry->getRepository(User::class);
        $user = $userRepo->find($userId);
        if (!$user instanceof User) {
            return new Error(['error' => 'User not found'], 404);
        }

        try {
            /** @var \App\Repository\EntryRepository $entryRepo */
            $entryRepo = $this->managerRegistry->getRepository(Entry::class);
            $entry = null;

            if ($dto->id !== null) {
                $entry = $entryRepo->find($dto->id);
                if (!$entry instanceof Entry) {
                    return new Error(['error' => 'Entry not found'], 404);
                }
            }

            // validation
            if ($dto->customer === 0) {
                throw new Exception('Please select a customer');
            }

            if ($dto->project === 0) {
                throw new Exception('Please select a project');
            }

            if ($dto->activity === 0) {
                throw new Exception('Please select an activity');
            }

            if ($dto->start === null) {
                throw new Exception('Please enter a start');
            }

            if ($dto->end === null) {
                throw new Exception('Please enter an end');
            }

            if ($dto->start > $dto->end) {
                throw new Exception('Start must not be greater than End');
            }

            // Check if the entry overlaps with existing entries
            $start = $dto->start->setTime(0, 0);
            $end = $dto->end->setTime(23, 59, 59);
            $existingEntries = $entryRepo->findEntriesByUserAndDateRange($userId, $start, $end);

            foreach ($existingEntries as $existingEntry) {
                // Skip if this is the same entry being edited
                if ($entry !== null && $existingEntry->getId() === $entry->getId()) {
                    continue;
                }

                $existingStart = $existingEntry->getStart();
                $existingEnd = $existingEntry->getEnd();

                // Check for overlap
                if (
                    $dto->start < $existingEnd && $dto->end > $existingStart
                ) {
                    $message = $this->translator->trans(
                        'Time entry overlaps with existing entry from %start% to %end%',
                        [
                            '%start%' => $existingStart->format('H:i'),
                            '%end%' => $existingEnd->format('H:i'),
                        ],
                    );
                    throw new Exception($message);
                }
            }

            $customer = RequestEntityHelper::getEntityFromRequest($this->managerRegistry, Customer::class, $dto->customer);
            $project = RequestEntityHelper::getEntityFromRequest($this->managerRegistry, Project::class, $dto->project);
            $activity = RequestEntityHelper::getEntityFromRequest($this->managerRegistry, Activity::class, $dto->activity);

            if ($entry === null) {
                $entry = new Entry();
                $entry->setUser($user);
            }

            $entry->setCustomer($customer);
            $entry->setProject($project);
            $entry->setActivity($activity);
            $entry->setStart($dto->start);
            $entry->setEnd($dto->end);
            $entry->setTicket($dto->ticket ?? '');
            $entry->setDescription($dto->description ?? '');

            $this->managerRegistry->getManager()->persist($entry);
            $this->managerRegistry->getManager()->flush();

            // write log
            $this->logData($entry->toArray());

            // Check if the activity needs a ticket (business logic that requires entity context)
            $activity = $entry->getActivity();
            if (UserType::DEV === $user->getType() && $activity instanceof Activity && $activity->getNeedsTicket() && '' === $entry->getTicket()) {
                $message = $this->translator->trans(
                    "For the activity '%activity%' you must specify a ticket.",
                    [
                        '%activity%' => $activity->getName(),
                    ],
                );
                throw new Exception($message);
            }

            // prepare data for jira
            $this->createJiraEntry($entry, $user);

            return new JsonResponse(['success' => true, 'id' => $entry->getId()]);
        } catch (Throwable $exception) {
            if ($this->managerRegistry->getManager()->isOpen()) {
                $this->managerRegistry->getManager()->rollback();
            }

            return new Error(['error' => $exception->getMessage()]);
        }
    }
}