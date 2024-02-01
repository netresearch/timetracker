<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\Activity;
use Netresearch\TimeTrackerBundle\Entity\Customer;
use Netresearch\TimeTrackerBundle\Entity\Project;
use Netresearch\TimeTrackerBundle\Entity\Entry as Entry;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Response\Error;
use Netresearch\TimeTrackerBundle\Helper\JiraApiException;
use Netresearch\TimeTrackerBundle\Helper\JiraApiUnauthorizedException;
use Netresearch\TimeTrackerBundle\Helper\JiraOAuthApi;
use Netresearch\TimeTrackerBundle\Helper\TicketHelper;

use Netresearch\TimeTrackerBundle\Model\JsonResponse;
use Netresearch\TimeTrackerBundle\Model\Response;
use Symfony\Component\HttpFoundation\Request;

class CrudController extends BaseController
{
    const LOG_FILE = 'trackingsave.log';

    public function deleteAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $alert = null;

        if (0 != $request->request->get('id')) {
            $doctrine = $this->getDoctrine();
            /** @var Entry $entry */
            $entry = $doctrine->getRepository('NetresearchTimeTrackerBundle:Entry')
                ->find($request->request->get('id'));

            try {
                $this->deleteJiraWorklog($entry);

            } catch (JiraApiUnauthorizedException $e) {
                // Invalid JIRA token
                return new Error($e->getMessage(), 403, $e->getRedirectUrl());

            } catch (JiraApiException $e) {
                $alert = $e->getMessage() . '<br />' .
                    $this->get('translator')->trans("Dataset was modified in Timetracker anyway");
            }

            // remember the day to calculate classes afterwards
            $day = $entry->getDay()->format("Y-m-d");

            $manager = $doctrine->getManager();
            $manager->remove($entry);
            $manager->flush();

            // We have to update classes after deletion as well
            $this->calculateClasses($this->getUserId($request), $day);
        }

        return new JsonResponse(array('success' => true, 'alert' => $alert));
    }

    /**
     * Deletes a work log entry in a remote JIRA installation.
     * JIRA instance is defined by ticket system in project.
     *
     * @param Entry             $entry
     * @param TicketSystem|null $ticketSystem
     * @return void
     * @throws JiraApiException
     */
    private function deleteJiraWorklog(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ) {
        $project = $entry->getProject();
        if (! $project instanceof Project) {
            return;
        }

        if (empty($ticketSystem)) {
            $ticketSystem = $project->getTicketSystem();
        }

        if ($project->hasInternalJiraProjectKey()) {
            $ticketSystem = $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:TicketSystem')
                ->find($project->getInternalJiraTicketSystem());
        }

        if (! $ticketSystem instanceof TicketSystem) {
            return;
        }

        if (! $ticketSystem->getBookTime() || $ticketSystem->getType() != 'JIRA') {
            return;
        }

        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(), $ticketSystem, $this->getDoctrine(), $this->container->get('router')
        );
        $jiraOAuthApi->deleteEntryJiraWorkLog($entry);
    }



    /**
     * Set rendering classes for pause, overlap and daybreak.
     *
     * @param integer $userId
     * @param string  $day
     * @return void
     */
    private function calculateClasses($userId, $day)
    {
        if (! (int) $userId) {
            return;
        }

        $doctrine = $this->getDoctrine();
        $manager = $doctrine->getManager();
        /* @var $entries Entry[] */
        $entries = $doctrine->getRepository('NetresearchTimeTrackerBundle:Entry')
            ->findByDay((int) $userId, $day);

        if (!count($entries)) {
            return;
        }

        if (! is_object($entries[0])) {
            return;
        }

        $entry = $entries[0];
        if ($entry->getClass() != Entry::CLASS_DAYBREAK) {
            $entry->setClass(Entry::CLASS_DAYBREAK);
            $manager->persist($entry);
            $manager->flush();
        }

        for ($c = 1; $c < count($entries); $c++) {
            $entry = $entries[$c];
            $previous = $entries[$c-1];

            if ($entry->getStart()->format("H:i") > $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_PAUSE) {
                    $entry->setClass(Entry::CLASS_PAUSE);
                    $manager->persist($entry);
                    $manager->flush();
                }
                continue;
            }

            if ($entry->getStart()->format("H:i") < $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_OVERLAP) {
                    $entry->setClass(Entry::CLASS_OVERLAP);
                    $manager->persist($entry);
                    $manager->flush();
                }
                continue;
            }

            if ($entry->getClass() != Entry::CLASS_PLAIN) {
                $entry->setClass(Entry::CLASS_PLAIN);
                $manager->persist($entry);
                $manager->flush();
            }
        }
    }



    /**
     * Save action handler.
     *
     * @param Request $request
     * @return Error|Response
     */
    public function saveAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $alert = null;
            $this->logDataToFile($_POST, TRUE);

            $doctrine = $this->getDoctrine();

            if($request->get('id') != 0) {
                $entry = $doctrine->getRepository('NetresearchTimeTrackerBundle:Entry')
                    ->find($request->get('id'));
            } else {
                $entry = new Entry();
            }

            // We make a copy to determine if we have to update JIRA
            $oldEntry = clone $entry;

            /** @var Project $project */
            if ($project = $doctrine->getRepository('NetresearchTimeTrackerBundle:Project')->find($request->get('project'))) {
                if (! $project->getActive()) {
                    $message = $this->get('translator')->trans("This project is inactive and cannot be used for booking.");
                    throw new \Exception($message);
                }
                $entry->setProject($project);
            }

            /** @var Customer $customer */
            if ($customer = $doctrine->getRepository('NetresearchTimeTrackerBundle:Customer')->find($request->get('customer'))) {
                if (! $customer->getActive()) {
                    $message = $this->get('translator')->trans("This customer is inactive and cannot be used for booking.");
                    throw new \Exception($message);
                }
                $entry->setCustomer($customer);
            }

            /* @var $user \Netresearch\TimeTrackerBundle\Entity\User */
            $user = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')
                ->find($this->getUserId($request));
            $entry->setUser($user);

            if ($project->hasInternalJiraProjectKey()) {
                $ticketSystem = $this->getDoctrine()
                    ->getRepository('NetresearchTimeTrackerBundle:TicketSystem')
                    ->find($project->getInternalJiraTicketSystem());
            } else {
                $ticketSystem = $project->getTicketSystem();
            }

            if ($ticketSystem != null) {
                if (!$ticketSystem instanceof TicketSystem) {
                    $message = 'Einstellungen für das Ticket System überprüfen';
                    return $this->getFailedResponse($message ,400);
                }

                $jiraOAuthApi = new JiraOAuthApi(
                    $entry->getUser(), $ticketSystem, $doctrine, $this->container->get('router')
                );

                if (! $project->hasInternalJiraProjectKey()) {
                    // ticekts do not exist for external project tickets booked on internal ticket system
                    // so no need to check for existence
                    // they are created automatically
                    if ($request->get('ticket') != ''
                        && !$jiraOAuthApi->doesTicketExist($request->get('ticket'))
                    ) {
                        $message = $request->get('ticket') . ' existiert nicht';
                        throw new \Exception($message);
                    }
                }
            }

            /** @var Activity $activity */
            if ($activity = $doctrine->getRepository('NetresearchTimeTrackerBundle:Activity')->find($request->get('activity'))) {
                $entry->setActivity($activity);
            }

            $entry->setTicket(strtoupper(trim($request->get('ticket') ? $request->get('ticket') : '')))
                ->setDescription($request->get('description') ? $request->get('description') : '')
                ->setDay($request->get('date') ? $request->get('date') : null)
                ->setStart($request->get('start') ? $request->get('start') : null)
                ->setEnd($request->get('end') ? $request->get('end') : null)
                ->setInternalJiraTicketOriginalKey($request->get('extTicket') ? $request->get('extTicket') : null)
                // ->calcDuration(is_object($activity) ? $activity->getFactor() : 1);
                ->calcDuration()
                ->setSyncedToTicketsystem(FALSE);

            // write log
            $this->logDataToFile($entry->toArray());

            // Check if the activity needs a ticket
            if (($user->getType() == 'DEV') && is_object($activity) && $activity->getNeedsTicket()) {
                if (strlen($entry->getTicket()) < 1) {
                    $message = $this->get('translator')
                        ->trans(
                            "For the activity '%activity%' you must specify a ticket.",
                            array(
                                '%activity%' => $activity->getName(),
                            )
                        );
                    throw new \Exception($message);
                }
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
                    $user->getId(), $entry->getDay()->format("Y-m-d")
                );
                // and the previous day, if the entry was moved
                if (is_object($oldEntry->getDay())) {
                    if ($entry->getDay()->format("Y-m-d") != $oldEntry->getDay()->format("Y-m-d"))
                        $this->calculateClasses(
                            $user->getId(), $oldEntry->getDay()->format("Y-m-d")
                        );
                }
            }

            // update JIRA, if necessary
            try {
                $this->updateJiraWorklog($entry, $oldEntry);
                // Save potential worklog ID
                $em->persist($entry);
                $em->flush();
            } catch (JiraApiException $e) {
                if ($e instanceof JiraApiUnauthorizedException) {
                    throw $e;
                }
                $alert = $e->getMessage() . '<br />' .
                    $this->get('translator')->trans("Dataset was modified in Timetracker anyway");
            }

            $response = array(
                'result' => $entry->toArray(),
                'alert'  => $alert
            );

            return new JsonResponse($response);

        } catch (JiraApiUnauthorizedException $e) {
            // Invalid JIRA token
            return new Error($e->getMessage(), 403, $e->getRedirectUrl(), $e);

        } catch (\Exception $e) {
            return new Error($this->get('translator')->trans($e->getMessage()), 406, null, $e);

        } catch (\Throwable $e) {
            return new Error($e->getMessage(), 503, null, $e);
        }
    }


    /**
     * Inserts a series of same entries by preset
     *
     * @param Request $request
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
            $this->logDataToFile($_POST, TRUE);

            $doctrine = $this->getDoctrine();

            $preset = $doctrine->getRepository('NetresearchTimeTrackerBundle:Preset')->find((int) $request->get('preset'));
            if (! is_object($preset))
                throw new \Exception('Preset not found');

            // Retrieve needed objects
            /** @var User $user */
            $user     = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')
                ->find($this->getUserId($request));
            /** @var Customer $customer */
            $customer = $doctrine->getRepository('NetresearchTimeTrackerBundle:Customer')
                ->find($preset->getCustomerId());
            /** @var Project $project */
            $project  = $doctrine->getRepository('NetresearchTimeTrackerBundle:Project')
                ->find($preset->getProjectId());
            /** @var Activity $activity */
            $activity = $doctrine->getRepository('NetresearchTimeTrackerBundle:Activity')
                ->find($preset->getActivityId());
            $em = $doctrine->getManager();

            $date = new \DateTime($request->get('startdate'));
            $endDate = new \DateTime($request->get('enddate'));

            $c = 0;

            // define weekends
            $weekend = array('0','6','7');

            // define regular holidays
            $regular_holidays = array(
                "01-01",
                "05-01",
                "10-03",
                "10-31",
                "12-25",
                "12-26"
            );

            // define irregular holidays
            $irregular_holidays = array(
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
            );

            do {
                // some loop security
                $c++;
                if ($c > 100) break;

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

                $entry = new Entry();
                $entry->setUser($user)
                    ->setTicket('')
                    ->setDescription($preset->getDescription())
                    ->setDay($date)
                    ->setStart($request->get('starttime') ? $request->get('starttime') : null)
                    ->setEnd($request->get('endtime') ? $request->get('endtime') : null)
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
                $this->logDataToFile($entry->toArray());

                $em->persist($entry);
                $em->flush();

                // calculate color lines for the changed days
                $this->calculateClasses($user->getId(), $entry->getDay()->format("Y-m-d"));

                // print $date->format('d.m.Y') . " was saved.<br/>";
                $date->add(new \DateInterval('P1D'));
            } while ($date <= $endDate);

            $response = new Response($this->get('translator')->trans('All entries have been saved.'));
            $response->setStatusCode(200);
            return $response;

        } catch (\Exception $e) {
            $response = new Response($this->get('translator')->trans($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }
    }



    /**
     * Ensures valid ticket number format.
     *
     * @param $ticket
     * @return void
     * @throws \Exception
     */
    private function requireValidTicketFormat($ticket)
    {
        // do not check empty tickets
        if (strlen($ticket) < 1) {
            return;
        }

        if (! TicketHelper::checkFormat($ticket)) {
            $message = $this->get('translator')->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        return;
    }



    /**
     * TTT-199: check if ticket prefix matches project's Jira id.
     *
     * @param Project $project
     * @param string $ticket
     * @throws \Exception
     * @return void
     */
    private function requireValidTicketPrefix(Project $project, $ticket)
    {
        // do not check empty tickets
        if (strlen($ticket) < 1) {
            return;
        }

        // do not check empty jira-projects
        if (strlen($project->getJiraId()) < 1) {
            return;
        }

        if (! TicketHelper::checkFormat($ticket)) {
            $message = $this->get('translator')->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        $jiraId = TicketHelper::getPrefix($ticket);
        $projectIds = explode(",", $project->getJiraId());

        foreach ($projectIds as $pId) {
            if (trim($pId) == $jiraId || $project->matchesInternalJiraProject($jiraId)) {
                return;
            }
        }

        $message = $this->get('translator')->trans(
            "The ticket's Jira ID '%ticket_jira_id%' does not match the project's Jira ID '%project_jira_id%'.",
            array('%ticket_jira_id%' => $jiraId, '%project_jira_id%' => $project->getJiraId())
        );

        throw new \Exception($message);
    }



    /**
     * Write log entry to log file.
     *
     * @param array $data
     * @param bool  $raw
     * @throws \Exception
     */
    private function logDataToFile(array $data, $raw = FALSE)
    {
        $file = $this->get('kernel')->getRootDir() . '/logs/' . self::LOG_FILE;
        if (!file_exists($file) && !touch($file)) {
            throw new \Exception(
                $this->get('translator')->trans(
                    'Could not create log file: %log_file%',
                    array('%log_file%' => $file)
                )
            );
        }

        if (!is_writable($file)) {
            throw new \Exception(
                $this->get('translator')->trans(
                    'Cannot write to log file: %log_file%',
                    array('%log_file%' => $file)
                )
            );
        }

        $log = sprintf(
            '[%s][%s]: %s %s',
            date('d.m.Y H:i:s'),
            ($raw ? 'raw' : 'obj'),
            json_encode($data),
            PHP_EOL
        );

        file_put_contents($file, $log, FILE_APPEND);
    }


    /**
     * Updates a JIRA work log entry.
     *
     * @param Entry $entry
     * @param Entry $oldEntry
     *
     * @param TicketSystem|null $ticketSystem
     * @return void
     * @throws JiraApiException
     * @throws \Netresearch\TimeTrackerBundle\Helper\JiraApiInvalidResourceException
     */
    private function updateJiraWorklog(
        Entry $entry,
        Entry $oldEntry,
        TicketSystem $ticketSystem = null
    ){
        $project = $entry->getProject();
        if (! $project instanceof Project) {
            return;
        }

        if (empty($ticketSystem)) {
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
            // delete old worklog - new one will be created later
            $this->deleteJiraWorklog($oldEntry, $ticketSystem);
            $entry->setWorklogId(NULL);
        }

        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(), $ticketSystem, $this->getDoctrine(), $this->container->get('router')
        );
        $jiraOAuthApi->updateEntryJiraWorkLog($entry);
    }


    /**
     * Creates an Ticket in the given ticketSystem
     *
     * @param Entry $entry
     * @param TicketSystem|null $ticketSystem
     * @return string
     *
     * @throws JiraApiException
     * @throws \Netresearch\TimeTrackerBundle\Helper\JiraApiInvalidResourceException
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-create-issue
     */
    protected function createTicket(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ) {
        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(), $ticketSystem, $this->getDoctrine(), $this->container->get('router')
        );
        $ticket = $jiraOAuthApi->createTicket($entry);

        return $ticket;
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
     * @throws \Netresearch\TimeTrackerBundle\Helper\JiraApiInvalidResourceException
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-query-issues
     */
    protected  function handleInternalJiraTicketSystem($entry, $oldEntry)
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
                ->getRepository('NetresearchTimeTrackerBundle:TicketSystem')
                ->find($internalJiraTicketSystem);

        // check if issue exist
        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(), $internalJiraTicketSystem, $this->getDoctrine(), $this->container->get('router')
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
        if (count($searchResult->issues)) {
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
     *
     * @param Entry $entry
     * @param Entry $oldEntry
     * @return bool
     */
    protected function shouldTicketBeDeleted(Entry $entry, Entry $oldEntry)
    {
        $bDifferentTickets
            = $oldEntry->getTicket() != $entry->getTicket();
        $bIsCurrentTicketOriginalTicket
            = $entry->getInternalJiraTicketOriginalKey() === $entry->getTicket();

        return !$bIsCurrentTicketOriginalTicket && $bDifferentTickets;
    }
}
