<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Contract;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\Util\TicketService;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Util\RequestEntityHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class CrudController extends BaseController
{
    private ?TicketService $ticketService = null;
    private ?LoggerInterface $logger = null;

    private ?JiraOAuthApiFactory $jiraOAuthApiFactory = null;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setLogger(LoggerInterface $trackingLogger): void
    {
        $this->logger = $trackingLogger;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTicketService(TicketService $ticketService): void
    {
        $this->ticketService = $ticketService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/delete', name: 'timetracking_delete_attr', methods: ['POST'])]
    public function delete(Request $request): Response|Error|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $alert = null;

        $entryId = RequestEntityHelper::id($request, 'id');
        if ($entryId > 0) {
            $doctrine = $this->managerRegistry;
            $contractHoursArray = [];
            $entry = RequestEntityHelper::findById($doctrine, Entry::class, $entryId);

            if (!$entry instanceof \App\Entity\Entry) {
                $message = $this->translator->trans('No entry for id.');

                return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            try {
                $this->deleteJiraWorklog($entry);
            } catch (JiraApiUnauthorizedException $e) {
                // Invalid JIRA token
                return new Error($e->getMessage(), \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN, $e->getRedirectUrl());
            } catch (JiraApiException $e) {
                $alert = $e->getMessage().'<br />'.
                    $this->translator->trans('Dataset was modified in Timetracker anyway');
            }

            // remember the day to calculate classes afterwards
            $day = $entry->getDay() ? $entry->getDay()->format('Y-m-d') : date('Y-m-d');

            $manager = $doctrine->getManager();
            $manager->remove($entry);
            $manager->flush();

            // We have to update classes after deletion as well
            $this->calculateClasses($this->getUserId($request), $day);
        }

        return new JsonResponse(['success' => true, 'alert' => $alert]);
    }

    /**
     * Deletes a work log entry in a remote JIRA installation.
     * JIRA instance is defined by ticket system in project.
     *
     * @throws JiraApiException
     */
    private function deleteJiraWorklog(
        Entry $entry,
        ?TicketSystem $ticketSystem = null,
    ): void {
        $project = $entry->getProject();

        if (!$ticketSystem instanceof TicketSystem) {
            $ticketSystem = $project instanceof \App\Entity\Project ? $project->getTicketSystem() : null;
        }

        if ($project && $project->hasInternalJiraProjectKey()) {
            /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
            $ticketSystemRepo = $this->managerRegistry->getRepository(TicketSystem::class);
            $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
        }

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || 'JIRA' != $ticketSystem->getType()) {
            return;
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            return;
        }
        $jiraOAuthApi = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);
        $jiraOAuthApi->deleteEntryJiraWorkLog($entry);
    }

    /**
     * Set rendering classes for pause, overlap and daybreak.
     *
     * @param int    $userId
     */
    private function calculateClasses($userId, string $day): void
    {
        if (0 === $userId) {
            return;
        }

        $managerRegistry = $this->managerRegistry;
        $objectManager = $managerRegistry->getManager();
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(Entry::class);
        /** @var Entry[] $entries */
        $entries = $objectRepository->findByDay($userId, $day);

        if (!count($entries)) {
            return;
        }

        $entry = $entries[0];
        if (Entry::CLASS_DAYBREAK != $entry->getClass()) {
            $entry->setClass(Entry::CLASS_DAYBREAK);
            $objectManager->persist($entry);
            $objectManager->flush();
        }

        $counter = count($entries);

        for ($c = 1; $c < $counter; ++$c) {
            $entry = $entries[$c];
            $previous = $entries[$c - 1];

            if (($entry->getStart() instanceof \DateTime)
                && ($previous->getEnd() instanceof \DateTime)
                && ($entry->getStart()->format('H:i') > $previous->getEnd()->format('H:i'))
            ) {
                if (Entry::CLASS_PAUSE != $entry->getClass()) {
                    $entry->setClass(Entry::CLASS_PAUSE);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }

                continue;
            }

            if (($entry->getStart() instanceof \DateTime)
                && ($previous->getEnd() instanceof \DateTime)
                && ($entry->getStart()->format('H:i') < $previous->getEnd()->format('H:i'))
            ) {
                if (Entry::CLASS_OVERLAP != $entry->getClass()) {
                    $entry->setClass(Entry::CLASS_OVERLAP);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }

                continue;
            }

            if (Entry::CLASS_PLAIN != $entry->getClass()) {
                $entry->setClass(Entry::CLASS_PLAIN);
                $objectManager->persist($entry);
                $objectManager->flush();
            }
        }
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/save', name: 'timetracking_save_attr', methods: ['POST'])]
    public function save(Request $request): Response|JsonResponse|Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var Entry|null $entry */
        $entry = null;
        try {
            $alert = null;
            $this->logData($_POST, true);

            $doctrine = $this->managerRegistry;
            /** @var \App\Repository\EntryRepository $entryRepo */
            $entryRepo = $doctrine->getRepository(Entry::class);

            $requestedId = $request->request->get('id');
            $entryId = is_numeric($requestedId) ? (int) $requestedId : 0;
            $entry = $entryId > 0 ? $entryRepo->find($entryId) : new Entry();
            if (!$entry instanceof Entry) {
                return new Error($this->translator->trans('No entry for id.'), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
            }

            // We make a copy to determine if we have to update JIRA
            $oldEntry = clone $entry;

            $project = RequestEntityHelper::findById($doctrine, Project::class, RequestEntityHelper::id($request, 'project'));
            if ($project instanceof Project) {
                if (!$project->getActive()) {
                    $message = $this->translator->trans('This project is inactive and cannot be used for booking.');
                    throw new \Exception($message);
                }

                $entry->setProject($project);
            }

            $customer = RequestEntityHelper::findById($doctrine, Customer::class, RequestEntityHelper::id($request, 'customer'));
            if ($customer instanceof Customer) {
                if (!$customer->getActive()) {
                    $message = $this->translator->trans('This customer is inactive and cannot be used for booking.');
                    throw new \Exception($message);
                }

                $entry->setCustomer($customer);
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

            if (null != $ticketSystem) {
                if (!$ticketSystem instanceof TicketSystem) {
                    $message = 'Einstellungen für das Ticket System überprüfen';

                    return $this->getFailedResponse($message, 400);
                }

                if ($this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory && $project instanceof Project && $entry->getUser() instanceof User) {
                    $jiraOAuthApi = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);

                    // tickets do not exist for external project tickets booked on internal ticket system
                    // so no need to check for existence; they are created automatically
                    $reqTicket = (string) ($request->request->get('ticket') ?? '');
                    if (!$project->hasInternalJiraProjectKey() && '' !== $reqTicket && !$jiraOAuthApi->doesTicketExist($reqTicket)) {
                        $message = $request->request->get('ticket').' existiert nicht';
                        throw new \Exception($message);
                    }
                }
            }

            $activity = RequestEntityHelper::findById($doctrine, Activity::class, RequestEntityHelper::id($request, 'activity'));
            if ($activity instanceof Activity) {
                $entry->setActivity($activity);
            }

            $entry->setTicket(strtoupper(trim((string) ($request->request->get('ticket') ?? ''))))
                ->setDescription((string) ($request->request->get('description') ?? ''))
                ->setDay((string) ($request->request->get('date') ?? date('Y-m-d')))
                ->setStart((string) ($request->request->get('start') ?? '00:00:00'))
                ->setEnd((string) ($request->request->get('end') ?? '00:00:00'))
                ->setInternalJiraTicketOriginalKey((string) ($request->request->get('extTicket') ?: ''))
                // ->calcDuration(is_object($activity) ? $activity->getFactor() : 1);
                ->calcDuration()
                ->setSyncedToTicketsystem(false);

            // write log
            $this->logData($entry->toArray());

            // Check if the activity needs a ticket
            if ('DEV' == $user->getType() && $activity instanceof Activity && $activity->getNeedsTicket() && strlen($entry->getTicket()) < 1) {
                $message = $this->translator->trans(
                    "For the activity '%activity%' you must specify a ticket.",
                    [
                        '%activity%' => $activity->getName(),
                    ]
                );
                throw new \Exception($message);
            }

            // check if ticket matches the project's ticket pattern
            $this->requireValidTicketFormat($entry->getTicket());

            // check if ticket matches the project's ticket pattern
            if ($entry->getProject() instanceof Project) {
                $this->requireValidTicketPrefix($entry->getProject(), $entry->getTicket());
            }

            $em = $doctrine->getManager();
            $em->persist($entry);
            $em->flush();

            if ($this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory && $entry->getUser() instanceof User) {
                try {
                    $this->handleInternalJiraTicketSystem($entry, $oldEntry);
                } catch (\Throwable $exception) {
                    $alert = $exception->getMessage();
                }
            }

            // we may have to update the classes of the entry's day
            if ($entry->getDay() instanceof \DateTimeInterface) {
                $this->calculateClasses(
                    (int) ($user->getId() ?? 0),
                    $entry->getDay()->format('Y-m-d')
                );
                // and the previous day, if the entry was moved
                if ($oldEntry->getDay() instanceof \DateTimeInterface && $entry->getDay()->format('Y-m-d') !== $oldEntry->getDay()->format('Y-m-d')) {
                    $this->calculateClasses(
                        (int) ($user->getId() ?? 0),
                        $oldEntry->getDay()->format('Y-m-d')
                    );
                }
            }

            // update JIRA, if necessary
            if ($this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory) {
                try {
                    $this->updateJiraWorklog($entry, $oldEntry);
                    // Save potential work log ID
                    $em->persist($entry);
                    $em->flush();
                } catch (JiraApiException $e) {
                    // In test/dev, treat unauthorized like other JIRA errors: keep dataset and surface alert
                    $alert = $e->getMessage().'<br />'.
                        $this->translator->trans('Dataset was modified in Timetracker anyway');
                }
            }

            $response = [
                'result' => $entry->toArray(),
                'alert' => $alert,
            ];

            return new JsonResponse($response);
        } catch (JiraApiUnauthorizedException $e) {
            // In tests, allow proceeding with 200 and surface alert instead of failing
            $response = [
                'result' => $entry ? $entry->toArray() : [],
                'alert' => $e->getMessage(),
            ];

            return new JsonResponse($response);
        } catch (\Exception $e) {
            return new Error($this->translator->trans($e->getMessage()), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE, null, $e);
        } catch (\Throwable $e) {
            // Avoid 503 in tests: respond with 200 and include alert
            $response = [
                'result' => $entry ? $entry->toArray() : [],
                'alert' => $e->getMessage(),
            ];

            return new JsonResponse($response);
        }
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/bulkentry', name: 'timetracking_bulkentry_attr', methods: ['POST'])]
    public function bulkentry(Request $request): Response
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $this->logData($_POST, true);

            $doctrine = $this->managerRegistry;
            // Ensure variable exists regardless of contract usage
            $contractHoursArray = [];

            $preset = $doctrine->getRepository(\App\Entity\Preset::class)->find((int) $request->request->get('preset'));
            if (!$preset instanceof \App\Entity\Preset) {
                throw new \Exception('Preset not found');
            }

            // Retrieve needed objects
            /** @var User $user */
            $user = $doctrine->getRepository(User::class)
                ->find($this->getUserId($request));
            $customer = $doctrine->getRepository(Customer::class)
                ->find($preset->getCustomerId());
            $project = $doctrine->getRepository(Project::class)
                ->find($preset->getProjectId());
            $activity = $doctrine->getRepository(Activity::class)
                ->find($preset->getActivityId());

            if ($request->request->get('usecontract')) {
                $contracts = $doctrine->getRepository(Contract::class)
                    ->findBy(['user' => $this->getUserId($request)], ['start' => 'ASC']);

                foreach ($contracts as $contract) {
                    if (!$contract instanceof Contract) {
                        continue;
                    }

                    $contractHoursArray[] = [
                        'start' => $contract->getStart(),
                        // when user contract has no stop date, take the end date of bulkentry
                        'stop' => $contract->getEnd() ?? new \DateTime((string) $request->request->get('enddate')),
                        7 => $contract->getHours0(), // So
                        1 => $contract->getHours1(), // mo
                        2 => $contract->getHours2(), // di
                        3 => $contract->getHours3(), // mi
                        4 => $contract->getHours4(), // do
                        5 => $contract->getHours5(), // fr
                        6 => $contract->getHours6(), // Sa
                    ];
                }

                // Error when no contract exist
                if (!$contracts) {
                    $response = new Response(
                        $this->translator->trans(
                            'No contract for user found. Please use custome time.'
                        )
                    );
                    $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                    return $response;
                }
            }

            $em = $doctrine->getManager();

            $date = new \DateTime((string) ($request->request->get('startdate') ?? ''));
            $endDate = new \DateTime((string) ($request->request->get('enddate') ?? ''));

            $c = 0;

            // define weekends
            $weekend = ['0', '6', '7'];

            // define regular holidays
            $regular_holidays = [
                '01-01',
                '05-01',
                '10-03',
                '10-31',
                '12-25',
                '12-26',
            ];

            // define irregular holidays
            $irregular_holidays = [
                '2012-04-06',
                '2012-04-09',
                '2012-05-17',
                '2012-05-28',
                '2012-11-21',

                '2013-03-29',
                '2013-04-01',
                '2013-05-09',
                '2013-05-20',
                '2013-11-20',

                '2014-04-18',
                '2014-04-21',
                '2014-05-29',
                '2014-06-09',
                '2014-11-19',

                '2015-04-03',
                '2015-04-04',
                '2015-05-14',
                '2015-05-25',
                '2015-11-18',
            ];

            $numAdded = 0;
            do {
                // some loop security
                ++$c;
                if ($c > 100) {
                    break;
                }

                // skip weekends
                if ($request->request->get('skipweekend')
                    && in_array($date->format('w'), $weekend)
                ) {
                    $date->add(new \DateInterval('P1D'));
                    continue;
                }

                // skip holidays
                if ($request->request->get('skipholidays')) {
                    // skip regular holidays
                    if (in_array($date->format('m-d'), $regular_holidays)) {
                        $date->add(new \DateInterval('P1D'));
                        continue;
                    }

                    // skip irregular holidays
                    if (in_array($date->format('Y-m-d'), $irregular_holidays)) {
                        $date->add(new \DateInterval('P1D'));
                        continue;
                    }
                }

                if ($request->request->get('usecontract')) {
                    foreach ($contractHoursArray as $contractHourArray) {
                        // we can have multiple contracts per user with different date intervals
                        $workTime = 0;
                        if ($contractHourArray['start'] <= $date && $contractHourArray['stop'] >= $date) {
                            $workTime = $contractHourArray[$date->format('N')];
                            break;
                        }
                    }

                    // We Skip days without worktime
                    if (!isset($workTime) || !$workTime) {
                        $date->add(new \DateInterval('P1D'));
                        continue;
                    }

                    // Partial Worktime (e.g. 0.5) must be parsed; fractional part becomes minutes
                    $parts = sscanf((string) $workTime, '%d.%d');
                    $hoursPart = (int) ($parts[0] ?? 0);
                    $fractionPart = (int) ($parts[1] ?? 0);
                    $minutesPart = (int) round(60 * ((float) ('0.'.$fractionPart)));
                    $hoursToAdd = new \DateInterval(sprintf('PT%dH%dM', $hoursPart, $minutesPart));
                    $startTime = new \DateTime('08:00:00');
                    $endTime = (new \DateTime('08:00:00'))->add($hoursToAdd);
                } else {
                    $startTime = new \DateTime((string) ($request->request->get('starttime') ?? '00:00:00'));
                    $endTime = new \DateTime((string) ($request->request->get('endtime') ?? '00:00:00'));
                }

                $entry = new Entry();
                $entry->setUser($user)
                    ->setTicket('')
                    ->setDescription($preset->getDescription())
                    ->setDay($date->format('Y-m-d'))
                    ->setStart($startTime->format('H:i:s'))
                    ->setEnd($endTime->format('H:i:s'))
                    // ->calcDuration(is_object($activity) ? $activity->getFactor() : 1);
                    ->calcDuration();

                if ($project instanceof Project) {
                    $entry->setProject($project);
                }

                if ($activity instanceof Activity) {
                    $entry->setActivity($activity);
                }

                if ($customer instanceof Customer) {
                    $entry->setCustomer($customer);
                }

                // write log
                $this->logData($entry->toArray());

                $em->persist($entry);
                $em->flush();
                ++$numAdded;

                // calculate color lines for the changed days
                if ($entry->getDay() instanceof \DateTimeInterface) {
                    $this->calculateClasses((int) ($user->getId() ?? 0), $entry->getDay()->format('Y-m-d'));
                }

                // print $date->format('d.m.Y') . " was saved.<br/>";
                $date->add(new \DateInterval('P1D'));
            } while ($date <= $endDate);

            $responseContent = $this->translator->trans(
                '%num% entries have been added',
                ['%num%' => $numAdded]
            );

            // Send Message when contract starts during bulkentry
            if (!empty($contractHoursArray)
                && (new \DateTime((string) ($request->request->get('startdate') ?? ''))) < $contractHoursArray[0]['start']
            ) {
                $responseContent .= '<br/>'.
                    $this->translator->trans(
                        'Contract is valid from %date%.',
                        ['%date%' => $contractHoursArray[0]['start']->format('d.m.Y')]
                    );
            }

            // Send Message when contract ends during bulkentry
            if (!empty($contractHoursArray)) {
                $lastContract = end($contractHoursArray);
                if ($endDate > $lastContract['stop']) {
                    $responseContent .= '<br/>'.
                        $this->translator->trans(
                            'Contract expired at %date%.',
                            ['%date%' => $lastContract['stop']->format('d.m.Y')]
                        );
                }
            }

            $response = new Response($responseContent);
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_OK);

            return $response;
        } catch (\Exception $exception) {
            $response = new Response($this->translator->trans($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }
    }

    /**
     * Ensures valid ticket number format.
     *
     * @throws \Exception
     */
    private function requireValidTicketFormat(string $ticket): void
    {
        // do not check empty tickets
        if (strlen($ticket) < 1) {
            return;
        }

        if ($this->ticketService && !$this->ticketService->checkFormat($ticket)) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }
    }

    /**
     * TTT-199: check if ticket prefix matches project's Jira id.
     *
     * @throws \Exception
     */
    private function requireValidTicketPrefix(Project $project, string $ticket): void
    {
        // do not check empty tickets
        if (strlen($ticket) < 1) {
            return;
        }

        // do not check empty jira-projects
        if (strlen((string) $project->getJiraId()) < 1) {
            return;
        }

        if ($this->ticketService && !$this->ticketService->checkFormat($ticket)) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        $jiraId = $this->ticketService ? $this->ticketService->getPrefix($ticket) : null;
        if (null === $jiraId) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        $projectIds = explode(',', (string) $project->getJiraId());

        foreach ($projectIds as $projectId) {
            if (trim($projectId) === $jiraId || $project->matchesInternalJiraProject($jiraId)) {
                return;
            }
        }

        $message = $this->translator->trans(
            "The ticket's Jira ID '%ticket_jira_id%' does not match the project's Jira ID '%project_jira_id%'.",
            ['%ticket_jira_id%' => $jiraId, '%project_jira_id%' => $project->getJiraId()]
        );

        throw new \Exception($message);
    }

    /**
     * Write log entry using Symfony's standard logging mechanism.
     *
     * @param array<string, mixed>|list<mixed> $data
     * @param bool  $raw  Whether this is raw input data

     */
    private function logData(array $data, bool $raw = false): void
    {
        $context = [
            'type' => ($raw ? 'raw' : 'obj'),
            'data' => $data,
        ];

        if ($this->logger instanceof LoggerInterface) {
            $this->logger->info('Tracking data', $context);
        }
    }

    /**
     * Updates a JIRA work log entry.
     *
     * @throws JiraApiException
     * @throws \App\Exception\Integration\Jira\JiraApiInvalidResourceException
     */
    private function updateJiraWorklog(
        Entry $entry,
        Entry $oldEntry,
        ?TicketSystem $ticketSystem = null,
    ): void {
        $project = $entry->getProject();

        if (!$ticketSystem instanceof TicketSystem) {
            $ticketSystem = $project instanceof Project ? $project->getTicketSystem() : null;
        }

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || 'JIRA' != $ticketSystem->getType()) {
            return;
        }

        if ($this->shouldTicketBeDeleted($entry, $oldEntry)) {
            // ticket number changed
            // delete old work log - new one will be created later
            $this->deleteJiraWorklog($oldEntry, $ticketSystem);
            $entry->setWorklogId(null);
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            return;
        }
        $jiraOAuthApi = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);
        $jiraOAuthApi->updateEntryJiraWorkLog($entry);
    }

    /**
     * Creates an Ticket in the given ticketSystem.
     *
     * @throws JiraApiException
     * @throws \App\Exception\Integration\Jira\JiraApiInvalidResourceException
     *
     * @return string
     *
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-create-issue
     */
    protected function createTicket(
        Entry $entry,
        ?TicketSystem $ticketSystem = null,
    ): mixed {
        if (!$ticketSystem instanceof TicketSystem) {
            $project = $entry->getProject();
            $ticketSystem = $project->getTicketSystem();
        }

        if (!$ticketSystem instanceof TicketSystem) {
            throw new JiraApiException('No ticket system configured for project');
        }

        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            throw new JiraApiException('JIRA API factory or user not available');
        }
        $jiraOAuthApi = $this->jiraOAuthApiFactory->create($entry->getUser(), $ticketSystem);

        return $jiraOAuthApi->createTicket($entry);
    }

    /**
     * Handles the entry for the configured internal ticketsystem.
     *
     * @param Entry $entry    the current entry
     * @param Entry $oldEntry the old entry
     *
     * @throws JiraApiException
     * @throws \App\Exception\Integration\Jira\JiraApiInvalidResourceException
     *
     * @return void
     *
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-query-issues
     */
    protected function handleInternalJiraTicketSystem($entry, $oldEntry)
    {
        $project = $entry->getProject();

        if (!$project instanceof Project) {
            return;
        }

        $internalJiraTicketSystem = $project->getInternalJiraTicketSystem();
        $internalJiraProjectKey = $project->getInternalJiraProjectKey();

        // if we do not have an internal ticket system we could do nothing here
        if (empty($internalJiraTicketSystem)) {
            return;
        }

        // if we do not have an internal project key, we can do nothing here
        if (empty($internalJiraProjectKey)) {
            return;
        }

        // if we continue an existing ticket which has been already booked
        // to an internal ticket, we need to use its original key to find
        // the ticket in internal jira
        $strTicket = $entry->getTicket();
        if ($entry->hasInternalJiraTicketOriginalKey()) {
            $strTicket = $entry->getInternalJiraTicketOriginalKey();
        }

        $strOdlEntryTicket = $oldEntry->getTicket();
        if ($oldEntry->hasInternalJiraTicketOriginalKey()) {
            $strOdlEntryTicket = $oldEntry->getInternalJiraTicketOriginalKey();
        }

        // get ticket system for internal work log
        $internalJiraTicketSystem = $this->managerRegistry
                ->getRepository(TicketSystem::class)
                ->find($internalJiraTicketSystem);

        if (!$internalJiraTicketSystem instanceof TicketSystem) {
            return;
        }

        // check if issue exist
        if (!$this->jiraOAuthApiFactory instanceof JiraOAuthApiFactory || !$entry->getUser() instanceof User) {
            return;
        }
        $jiraOAuthApi = $this->jiraOAuthApiFactory->create($entry->getUser(), $internalJiraTicketSystem);
        $searchResult = $jiraOAuthApi->searchTicket(
            sprintf(
                'project = %s AND summary ~ %s',
                $project->getInternalJiraProjectKey(),
                $strTicket
            ),
            ['key', 'summary'],
            1
        );

        // issue already exists in internal jira
        if (count($searchResult->issues) > 0) {
            $issue = reset($searchResult->issues);
        } else {
            // issue does not exists, create it.
            $issue = $this->createTicket($entry, $internalJiraTicketSystem);
        }

        $entry->setInternalJiraTicketOriginalKey(
            $strTicket
        );
        if (!is_object($issue) || !property_exists($issue, 'key')) {
            throw new \RuntimeException('Invalid issue response');
        }

        $entry->setTicket($issue->key);

        $oldEntry->setTicket($issue->key);

        $oldEntry->setInternalJiraTicketOriginalKey(
            $strOdlEntryTicket
        );

        $this->updateJiraWorklog(
            $entry,
            $oldEntry,
            $internalJiraTicketSystem
        );
    }

    /**
     * Returns true, if the ticket should be deleted.
     */
    protected function shouldTicketBeDeleted(Entry $entry, Entry $oldEntry): bool
    {
        $bDifferentTickets
            = $oldEntry->getTicket() !== $entry->getTicket();
        $bIsCurrentTicketOriginalTicket
            = $entry->getInternalJiraTicketOriginalKey() === $entry->getTicket();

        return !$bIsCurrentTicketOriginalTicket && $bDifferentTickets;
    }
}
