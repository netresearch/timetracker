<?php

namespace App\Controller;

use App\Entity\Activity;
use App\Entity\Contract;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Response\Error;
use App\Helper\JiraApiException;
use App\Helper\JiraApiUnauthorizedException;
use App\Helper\JiraOAuthApi;
use App\Helper\TicketHelper;

use App\Model\JsonResponse;
use App\Model\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class CrudController extends BaseController
{
    private \Psr\Log\LoggerInterface $logger;

    /**
     * @required
     */
    public function setLogger(LoggerInterface $trackingLogger): void
    {
        $this->logger = $trackingLogger;
    }

    public function deleteAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $alert = null;

        if (0 != $request->request->get('id')) {
            $doctrine = $this->getDoctrine();
            /** @var \App\Repository\EntryRepository $entryRepo */
            $entryRepo = $doctrine->getRepository(\App\Entity\Entry::class);
            /** @var Entry $entry */
            $entry = $entryRepo->find($request->request->get('id'));

            if (!$entry) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }

            try {
                $this->deleteJiraWorklog($entry);
            } catch (JiraApiUnauthorizedException $e) {
                // Invalid JIRA token
                return new Error($e->getMessage(), 403, $e->getRedirectUrl());
            } catch (JiraApiException $e) {
                $alert = $e->getMessage() . '<br />' .
                    $this->translator->trans("Dataset was modified in Timetracker anyway");
            }

            // remember the day to calculate classes afterwards
            $day = $entry->getDay()->format("Y-m-d");

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
     * @param TicketSystem|null $ticketSystem
     * @throws JiraApiException
     */
    private function deleteJiraWorklog(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ): void {
        $project = $entry->getProject();
        if (! $project instanceof Project) {
            return;
        }

        if (!$ticketSystem instanceof \App\Entity\TicketSystem) {
            $ticketSystem = $project->getTicketSystem();
        }

        if ($project->hasInternalJiraProjectKey()) {
            /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
            $ticketSystemRepo = $this->getDoctrine()->getRepository(\App\Entity\TicketSystem::class);
            $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
        }

        if (! $ticketSystem instanceof TicketSystem) {
            return;
        }

        if (! $ticketSystem->getBookTime() || $ticketSystem->getType() != 'JIRA') {
            return;
        }

        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $ticketSystem,
            $this->getDoctrine(),
            $this->router
        );
        $jiraOAuthApi->deleteEntryJiraWorkLog($entry);
    }

    /**
     * Set rendering classes for pause, overlap and daybreak.
     *
     * @param integer $userId
     * @param string  $day
     */
    private function calculateClasses($userId, $day): void
    {
        if ((int) $userId === 0) {
            return;
        }

        $managerRegistry = $this->getDoctrine();
        $objectManager = $managerRegistry->getManager();
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $managerRegistry->getRepository(\App\Entity\Entry::class);
        /** @var Entry[] $entries */
        $entries = $objectRepository->findByDay((int) $userId, $day);

        if (!count($entries)) {
            return;
        }

        if (! is_object($entries[0])) {
            return;
        }

        $entry = $entries[0];
        if ($entry->getClass() != Entry::CLASS_DAYBREAK) {
            $entry->setClass(Entry::CLASS_DAYBREAK);
            $objectManager->persist($entry);
            $objectManager->flush();
        }

        $counter = count($entries);

        for ($c = 1; $c < $counter; $c++) {
            $entry = $entries[$c];
            $previous = $entries[$c-1];

            if ($entry->getStart()->format("H:i") > $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_PAUSE) {
                    $entry->setClass(Entry::CLASS_PAUSE);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }

                continue;
            }

            if ($entry->getStart()->format("H:i") < $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_OVERLAP) {
                    $entry->setClass(Entry::CLASS_OVERLAP);
                    $objectManager->persist($entry);
                    $objectManager->flush();
                }

                continue;
            }

            if ($entry->getClass() != Entry::CLASS_PLAIN) {
                $entry->setClass(Entry::CLASS_PLAIN);
                $objectManager->persist($entry);
                $objectManager->flush();
            }
        }
    }

    /**
     * Save action handler.
     */
    public function saveAction(Request $request): \App\Model\Response|\App\Model\JsonResponse|\App\Response\Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $alert = null;
            $this->logData($_POST, true);

            $doctrine = $this->getDoctrine();
            /** @var \App\Repository\EntryRepository $entryRepo */
            $entryRepo = $doctrine->getRepository(\App\Entity\Entry::class);

            $entry = $request->get('id') != 0 ? $entryRepo->find($request->get('id')) : new Entry();

            // We make a copy to determine if we have to update JIRA
            $oldEntry = clone $entry;

            /** @var \App\Repository\ProjectRepository $projectRepo */
            $projectRepo = $doctrine->getRepository(\App\Entity\Project::class);
            /** @var Project $project */
            if ($project = $projectRepo->find($request->get('project'))) {
                if (! $project->getActive()) {
                    $message = $this->translator->trans("This project is inactive and cannot be used for booking.");
                    throw new \Exception($message);
                }

                $entry->setProject($project);
            }

            /** @var \App\Repository\CustomerRepository $customerRepo */
            $customerRepo = $doctrine->getRepository(\App\Entity\Customer::class);
            /** @var Customer $customer */
            if ($customer = $customerRepo->find($request->get('customer'))) {
                if (! $customer->getActive()) {
                    $message = $this->translator->trans("This customer is inactive and cannot be used for booking.");
                    throw new \Exception($message);
                }

                $entry->setCustomer($customer);
            }

            /** @var \App\Repository\UserRepository $userRepo */
            $userRepo = $doctrine->getRepository(\App\Entity\User::class);
            /** @var \App\Entity\User $user */
            $user = $userRepo->find($this->getUserId($request));
            $entry->setUser($user);

            if ($project->hasInternalJiraProjectKey()) {
                /** @var \App\Repository\TicketSystemRepository $ticketSystemRepo */
                $ticketSystemRepo = $doctrine->getRepository(\App\Entity\TicketSystem::class);
                $ticketSystem = $ticketSystemRepo->find($project->getInternalJiraTicketSystem());
            } else {
                $ticketSystem = $project->getTicketSystem();
            }

            if ($ticketSystem != null) {
                if (!$ticketSystem instanceof TicketSystem) {
                    $message = 'Einstellungen für das Ticket System überprüfen';
                    return $this->getFailedResponse($message, 400);
                }

                $jiraOAuthApi = new JiraOAuthApi(
                    $entry->getUser(),
                    $ticketSystem,
                    $doctrine,
                    $this->router
                );

                // ticekts do not exist for external project tickets booked on internal ticket system
                // so no need to check for existence
                // they are created automatically
                if (!$project->hasInternalJiraProjectKey() && ($request->get('ticket') != '' && !$jiraOAuthApi->doesTicketExist($request->get('ticket')))) {
                    $message = $request->get('ticket') . ' existiert nicht';
                    throw new \Exception($message);
                }
            }

            /** @var Activity $activity */
            if ($activity = $doctrine->getRepository(\App\Entity\Activity::class)->find($request->get('activity'))) {
                $entry->setActivity($activity);
            }

            $entry->setTicket(strtoupper(trim((string) ($request->get('ticket') ?: ''))))
                ->setDescription($request->get('description') ?: '')
                ->setDay($request->get('date') ?: null)
                ->setStart($request->get('start') ?: null)
                ->setEnd($request->get('end') ?: null)
                ->setInternalJiraTicketOriginalKey($request->get('extTicket') ?: null)
                // ->calcDuration(is_object($activity) ? $activity->getFactor() : 1);
                ->calcDuration()
                ->setSyncedToTicketsystem(false);

            // write log
            $this->logData($entry->toArray());

            // Check if the activity needs a ticket
            if ($user->getType() == 'DEV' && is_object($activity) && $activity->getNeedsTicket() && strlen((string) $entry->getTicket()) < 1) {
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
            $this->requireValidTicketPrefix($entry->getProject(), $entry->getTicket());

            $em = $doctrine->getManager();
            $em->persist($entry);
            $em->flush();

            try {
                $this->handleInternalJiraTicketSystem($entry, $oldEntry);
            } catch (\Throwable $exception) {
                $alert = $exception->getMessage();
            }

            // we may have to update the classes of the entry's day
            if (is_object($entry->getDay())) {
                $this->calculateClasses(
                    $user->getId(),
                    $entry->getDay()->format("Y-m-d")
                );
                // and the previous day, if the entry was moved
                if (is_object($oldEntry->getDay()) && $entry->getDay()->format("Y-m-d") != $oldEntry->getDay()->format("Y-m-d")) {
                    $this->calculateClasses(
                        $user->getId(),
                        $oldEntry->getDay()->format("Y-m-d")
                    );
                }
            }

            // update JIRA, if necessary
            try {
                $this->updateJiraWorklog($entry, $oldEntry);
                // Save potential work log ID
                $em->persist($entry);
                $em->flush();
            } catch (JiraApiException $e) {
                if ($e instanceof JiraApiUnauthorizedException) {
                    throw $e;
                }

                $alert = $e->getMessage() . '<br />' .
                    $this->translator->trans("Dataset was modified in Timetracker anyway");
            }

            $response = [
                'result' => $entry->toArray(),
                'alert'  => $alert
            ];

            return new JsonResponse($response);
        } catch (JiraApiUnauthorizedException $e) {
            // Invalid JIRA token
            return new Error($e->getMessage(), 403, $e->getRedirectUrl(), $e);
        } catch (\Exception $e) {
            return new Error($this->translator->trans($e->getMessage()), 406, null, $e);
        } catch (\Throwable $e) {
            return new Error($e->getMessage(), 503, null, $e);
        }
    }

    /**
     * Inserts a series of same entries by preset
     *
     *
     * @return Response
     */
    public function bulkentryAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $alert = null;
            $this->logData($_POST, true);

            $doctrine = $this->getDoctrine();

            $preset = $doctrine->getRepository(\App\Entity\Preset::class)->find((int) $request->get('preset'));
            if (! is_object($preset)) {
                throw new \Exception('Preset not found');
            }

            // Retrieve needed objects
            /** @var User $user */
            $user     = $doctrine->getRepository(\App\Entity\User::class)
                ->find($this->getUserId($request));
            /** @var Customer $customer */
            $customer = $doctrine->getRepository(\App\Entity\Customer::class)
                ->find($preset->getCustomerId());
            /** @var Project $project */
            $project  = $doctrine->getRepository(\App\Entity\Project::class)
                ->find($preset->getProjectId());
            /** @var Activity $activity */
            $activity = $doctrine->getRepository(\App\Entity\Activity::class)
                ->find($preset->getActivityId());

            if ($request->get('usecontract')) {
                /** @var Contract $contract */
                $contracts = $doctrine->getRepository(\App\Entity\Contract::class)
                    ->findBy(['user' => $this->getUserId($request)], ['start' => 'ASC']);

                $contractHoursArray = [];
                foreach ($contracts as $contract) {
                    $contractHoursArray[] = [
                        'start' => $contract->getStart(),
                        // when user contract has no stop date, take the end date of bulkentry
                        'stop'  => $contract->getEnd() ?? new \DateTime($request->get('enddate')),
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
                    $response->setStatusCode(406);
                    return $response;
                }
            }

            $em = $doctrine->getManager();

            $date = new \DateTime($request->get('startdate') ?: '');
            $endDate = new \DateTime($request->get('enddate') ?: '');

            $c = 0;

            // define weekends
            $weekend = ['0','6','7'];

            // define regular holidays
            $regular_holidays = [
                "01-01",
                "05-01",
                "10-03",
                "10-31",
                "12-25",
                "12-26"
            ];

            // define irregular holidays
            $irregular_holidays = [
                "2012-04-06",
                "2012-04-09",
                "2012-05-17",
                "2012-05-28",
                "2012-11-21",

                "2013-03-29",
                "2013-04-01",
                "2013-05-09",
                "2013-05-20",
                "2013-11-20",

                "2014-04-18",
                "2014-04-21",
                "2014-05-29",
                "2014-06-09",
                "2014-11-19",

                "2015-04-03",
                "2015-04-04",
                "2015-05-14",
                "2015-05-25",
                "2015-11-18",
            ];

            $numAdded = 0;
            do {
                // some loop security
                $c++;
                if ($c > 100) {
                    break;
                }

                // skip weekends
                if (($request->get('skipweekend'))
                    && (in_array($date->format('w'), $weekend))
                ) {
                    $date->add(new \DateInterval('P1D'));
                    continue;
                }

                // skip holidays
                if (($request->get('skipholidays'))) {
                    // skip regular holidays
                    if (in_array($date->format("m-d"), $regular_holidays)) {
                        $date->add(new \DateInterval('P1D'));
                        continue;
                    }

                    // skip irregular holidays
                    if (in_array($date->format("Y-m-d"), $irregular_holidays)) {
                        $date->add(new \DateInterval('P1D'));
                        continue;
                    }
                }

                if ($request->get('usecontract')) {
                    foreach ($contractHoursArray as $contractHourArray) {

                        // we can have multiple contracts per user with different date intervals
                        $workTime = 0;
                        if ($contractHourArray['start'] <= $date && $contractHourArray['stop'] >= $date) {
                            $workTime = $contractHourArray[$date->format('N')];
                            break;
                        }
                    }

                    // We Skip days without worktime
                    if (!$workTime) {
                        $date->add(new \DateInterval('P1D'));
                        continue;
                    }

                    // Partial Worktime (e.g 0.5) Must be parsed, Fractional minutes are calculated into full minutes
                    $workTime = sscanf($workTime, '%d.%d');
                    $hoursToAdd = new \DateInterval('PT' . $workTime[0] . 'H' . (60 * ('0.' . $workTime[1] ?? 0)) . 'M');
                    $startTime = new \DateTime('08:00:00');
                    $endTime = (new \DateTime('08:00:00'))->add($hoursToAdd);
                } else {
                    $startTime = new \DateTime($request->get('starttime') ?: null);
                    $endTime = new \DateTime($request->get('endtime') ?: null);
                }

                $entry = new Entry();
                $entry->setUser($user)
                    ->setTicket('')
                    ->setDescription($preset->getDescription())
                    ->setDay($date)
                    ->setStart($startTime->format('H:i:s'))
                    ->setEnd($endTime->format('H:i:s'))
                    //->calcDuration(is_object($activity) ? $activity->getFactor() : 1);
                    ->calcDuration();

                if ($project) {
                    $entry->setProject($project);
                }

                if ($activity) {
                    $entry->setActivity($activity);
                }

                if ($customer) {
                    $entry->setCustomer($customer);
                }

                // write log
                $this->logData($entry->toArray());

                $em->persist($entry);
                $em->flush();
                $numAdded++;

                // calculate color lines for the changed days
                $this->calculateClasses($user->getId(), $entry->getDay()->format("Y-m-d"));

                // print $date->format('d.m.Y') . " was saved.<br/>";
                $date->add(new \DateInterval('P1D'));
            } while ($date <= $endDate);

            $responseContent = $this->translator->trans(
                '%num% entries have been added',
                ['%num%' => $numAdded]
            );

            // Send Message when contract starts during bulkentry
            if (isset($contractHoursArray) && new \DateTime($request->get('startdate')) < $contractHoursArray[0]['start']) {
                $responseContent .= '<br/>' .
                    $this->translator->trans(
                        "Contract is valid from %date%.",
                        ['%date%' => $contractHoursArray[0]['start']->format('d.m.Y')]
                    );
            }

            // Send Message when contract ends during bulkentry
            if (isset($contractHoursArray) && $endDate > end($contractHoursArray)['stop']) {
                $responseContent .= '<br/>' .
                    $this->translator->trans(
                        "Contract expired at %date%.",
                        ['%date%' => end($contractHoursArray)['stop']->format('d.m.Y')]
                    );
            }

            $response = new Response($responseContent);
            $response->setStatusCode(200);
            return $response;
        } catch (\Exception $exception) {
            $response = new Response($this->translator->trans($exception->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }
    }

    /**
     * Ensures valid ticket number format.
     *
     * @param $ticket
     * @throws \Exception
     */
    private function requireValidTicketFormat($ticket): void
    {
        // do not check empty tickets
        if (strlen((string) $ticket) < 1) {
            return;
        }

        if (! TicketHelper::checkFormat($ticket)) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }
    }

    /**
     * TTT-199: check if ticket prefix matches project's Jira id.
     *
     * @param string $ticket
     * @throws \Exception
     */
    private function requireValidTicketPrefix(Project $project, $ticket): void
    {
        // do not check empty tickets
        if (strlen($ticket) < 1) {
            return;
        }

        // do not check empty jira-projects
        if (strlen((string) $project->getJiraId()) < 1) {
            return;
        }

        if (! TicketHelper::checkFormat($ticket)) {
            $message = $this->translator->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        $jiraId = TicketHelper::getPrefix($ticket);
        $projectIds = explode(",", (string) $project->getJiraId());

        foreach ($projectIds as $projectId) {
            if (trim($projectId) == $jiraId || $project->matchesInternalJiraProject($jiraId)) {
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
     * @param array $data The data to log
     * @param bool  $raw  Whether this is raw input data
     */
    private function logData(array $data, bool $raw = false): void
    {
        $context = [
            'type' => ($raw ? 'raw' : 'obj'),
            'data' => $data
        ];

        $this->logger->info('Tracking data', $context);
    }

    /**
     * Updates a JIRA work log entry.
     *
     *
     * @param TicketSystem|null $ticketSystem
     * @throws JiraApiException
     * @throws \App\Helper\JiraApiInvalidResourceException
     */
    private function updateJiraWorklog(
        Entry $entry,
        Entry $oldEntry,
        TicketSystem $ticketSystem = null
    ): void {
        $project = $entry->getProject();
        if (! $project instanceof Project) {
            return;
        }

        if (!$ticketSystem instanceof \App\Entity\TicketSystem) {
            $ticketSystem = $project->getTicketSystem();
        }

        if (! $ticketSystem instanceof TicketSystem) {
            return;
        }

        if (! $ticketSystem->getBookTime() || $ticketSystem->getType() != 'JIRA') {
            return;
        }

        if ($this->shouldTicketBeDeleted($entry, $oldEntry)) {
            // ticket number changed
            // delete old work log - new one will be created later
            $this->deleteJiraWorklog($oldEntry, $ticketSystem);
            $entry->setWorklogId(null);
        }

        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $ticketSystem,
            $this->getDoctrine(),
            $this->router
        );
        $jiraOAuthApi->updateEntryJiraWorkLog($entry);
    }

    /**
     * Creates an Ticket in the given ticketSystem
     *
     * @param TicketSystem|null $ticketSystem
     * @return string
     *
     * @throws JiraApiException
     * @throws \App\Helper\JiraApiInvalidResourceException
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-create-issue
     */
    protected function createTicket(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ): mixed {
        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $ticketSystem,
            $this->getDoctrine(),
            $this->router
        );

        return $jiraOAuthApi->createTicket($entry);
    }

    /**
     * Handles the entry for the configured internal ticketsystem.
     *
     * @param Entry $entry the current entry
     * @param Entry $oldEntry the old entry
     *
     * @return void
     *
     * @throws JiraApiException
     * @throws \App\Helper\JiraApiInvalidResourceException
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-query-issues
     */
    protected function handleInternalJiraTicketSystem($entry, $oldEntry)
    {
        $project = $entry->getProject();

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
        /** @var TicketSystem $internalJiraTicketSystem */
        $internalJiraTicketSystem = $this->getDoctrine()
                ->getRepository(\App\Entity\TicketSystem::class)
                ->find($internalJiraTicketSystem);

        // check if issue exist
        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $internalJiraTicketSystem,
            $this->getDoctrine(),
            $this->router
        );
        $searchResult = $jiraOAuthApi->searchTicket(
            sprintf(
                'project = %s AND summary ~ %s',
                $project->getInternalJiraProjectKey(),
                $strTicket
            ),
            ['key', 'summary'],
            1
        );

        //issue already exists in internal jira
        if (count($searchResult->issues) > 0) {
            $issue = reset($searchResult->issues);
        } else {
            //issue does not exists, create it.
            $issue = $this->createTicket($entry, $internalJiraTicketSystem);
        }

        $entry->setInternalJiraTicketOriginalKey(
            $strTicket
        );
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
            = $oldEntry->getTicket() != $entry->getTicket();
        $bIsCurrentTicketOriginalTicket
            = $entry->getInternalJiraTicketOriginalKey() === $entry->getTicket();

        return !$bIsCurrentTicketOriginalTicket && $bDifferentTickets;
    }
}
