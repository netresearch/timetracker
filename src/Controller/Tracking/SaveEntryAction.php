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
            return $this->getFailedLoginResponse();
        }

        $entry = new Entry();
        try {
            $alert = null;
            $this->logData($_POST, true);

            // DTO is automatically created, mapped, and validated by MapRequestPayload

            $doctrine = $this->managerRegistry;
            /** @var \App\Repository\EntryRepository $entryRepo */
            $entryRepo = $doctrine->getRepository(Entry::class);

            if ($dto->id > 0) {
                $foundEntry = $entryRepo->find($dto->id);
                if (!$foundEntry instanceof Entry) {
                    return new Error($this->translator->trans('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
                }

                $entry = $foundEntry;
            }

            // We make a copy to determine if we have to update JIRA
            $oldEntry = clone $entry;

            // Set entities from DTO
            if (null !== $dto->project_id) {
                $project = RequestEntityHelper::findById($doctrine, Project::class, (string) $dto->project_id);
                if ($project instanceof Project) {
                    if (!$project->getActive()) {
                        $message = $this->translator->trans('This project is inactive and cannot be used for booking.');
                        throw new Exception($message);
                    }
                    $entry->setProject($project);
                }
            }

            if (null !== $dto->customer_id) {
                $customer = RequestEntityHelper::findById($doctrine, Customer::class, (string) $dto->customer_id);
                if ($customer instanceof Customer) {
                    if (!$customer->getActive()) {
                        $message = $this->translator->trans('This customer is inactive and cannot be used for booking.');
                        throw new Exception($message);
                    }
                    $entry->setCustomer($customer);
                }
            }

            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $doctrine->getRepository(User::class);
            $user = $userRepo->find($this->getUserId($request));
            if (!$user instanceof User) {
                return new Error($this->translator->trans('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            $entry->setUser($user);

            // Ensure variables are defined for downstream logic
            $project = $entry->getProject();
            $ticketSystem = null;
            if ($project && $project->hasInternalJiraProjectKey()) {
                /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
                $ticketSystemRepo = $doctrine->getRepository(TicketSystem::class);
                $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
            } elseif ($project instanceof Project) {
                $ticketSystem = $project->getTicketSystem();
            }

            if (null !== $ticketSystem) {
                if (!$ticketSystem instanceof TicketSystem) {
                    $message = 'Einstellungen für das Ticket System überprüfen';

                    return $this->getFailedResponse($message, 400);
                }

                if ($project instanceof Project && $entry->getUser() instanceof User) {
                    $reqTicket = (string) ($request->request->get('ticket') ?? '');
                    // Only validate format/existence when service present
                    if (!$project->hasInternalJiraProjectKey() && '' !== $reqTicket && ($this->ticketService && !$this->ticketService->checkFormat($reqTicket))) {
                        $message = $request->request->get('ticket') . ' existiert nicht';
                        throw new Exception($message);
                    }
                }
            }

            // Set activity from DTO
            if (null !== $dto->activity_id) {
                $activity = RequestEntityHelper::findById($doctrine, Activity::class, (string) $dto->activity_id);
                if ($activity instanceof Activity) {
                    $entry->setActivity($activity);
                }
            }

            // Set all values from validated DTO
            $entry->setTicket($dto->ticket)
                ->setDescription($dto->description)
                ->setDay($dto->date)
                ->setStart($dto->start)
                ->setEnd($dto->end)
                ->setInternalJiraTicketOriginalKey($dto->extTicket)
                ->calcDuration()
                ->setSyncedToTicketsystem(false)
            ;

            // write log
            $this->logData($entry->toArray());

            // Check if the activity needs a ticket (business logic that requires entity context)
            $activity = $entry->getActivity();
            if ('DEV' === $user->getType() && $activity instanceof Activity && $activity->getNeedsTicket() && '' === $entry->getTicket()) {
                $message = $this->translator->trans(
                    "For the activity '%activity%' you must specify a ticket.",
                    [
                        '%activity%' => $activity->getName(),
                    ],
                );
                throw new Exception($message);
            }

            // check if ticket matches the project's ticket pattern
            $this->requireValidTicketFormat($entry->getTicket());

            if ($entry->getProject() instanceof Project) {
                $this->requireValidTicketPrefix($entry->getProject(), $entry->getTicket());
            }

            $em = $doctrine->getManager();
            $em->persist($entry);
            $em->flush();

            if ($entry->getUser() instanceof User) {
                try {
                    $this->handleInternalJiraTicketSystem($entry, $oldEntry);
                } catch (Throwable $exception) {
                    $alert = $exception->getMessage();
                }
            }

            // we may have to update the classes of the entry's day
            $this->calculateClasses($user->getId() ?? 0, $entry->getDay()->format('Y-m-d'));
            // and the previous day, if the entry was moved
            if ($entry->getDay()->format('Y-m-d') !== $oldEntry->getDay()->format('Y-m-d')) {
                $this->calculateClasses($user->getId() ?? 0, $oldEntry->getDay()->format('Y-m-d'));
            }

            // update JIRA, if necessary
            try {
                $this->updateJiraWorklog($entry, $oldEntry);
                // Save potential work log ID
                $em->persist($entry);
                $em->flush();
            } catch (\App\Exception\Integration\Jira\JiraApiException $e) {
                $alert = $e->getMessage() . '<br />' . $this->translator->trans('Dataset was modified in Timetracker anyway');
            }

            $response = [
                'result' => $entry->toArray(),
                'alert' => $alert,
            ];

            return new JsonResponse($response);
        } catch (\App\Exception\Integration\Jira\JiraApiUnauthorizedException $e) {
            $response = [
                'result' => $entry->toArray(),
                'alert' => $e->getMessage(),
            ];

            return new JsonResponse($response);
        } catch (Exception $e) {
            return new Error($this->translator->trans($e->getMessage()), \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY, null, $e);
        } catch (Throwable $e) {
            $response = [
                'result' => $entry->toArray(),
                'alert' => $e->getMessage(),
            ];

            return new JsonResponse($response);
        }
    }
}
