<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\Project;
use Netresearch\TimeTrackerBundle\Entity\Entry as Entry;
use Netresearch\TimeTrackerBundle\Entity\User as User;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\Ticket;
use Netresearch\TimeTrackerBundle\Helper\JiraClient;
use Netresearch\TimeTrackerBundle\Model\Jira as Jira;
use Netresearch\TimeTrackerBundle\Entity\UserTicketsystem;
use Netresearch\TimeTrackerBundle\Helper\ErrorResponse;
use Netresearch\TimeTrackerBundle\Helper\JiraUserApi;
use Netresearch\TimeTrackerBundle\Helper\TicketHelper;

use Netresearch\TimeTrackerBundle\Model\Response;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

use \Zend_Ldap as Zend_Ldap;
use \Zend_Ldap_Exception as Zend_Ldap_Exception;
use \Zend_Ldap_Dn as Zend_Ldap_Dn;

class CrudController extends BaseController
{
    const LOG_FILE = 'trackingsave.log';

    public function deleteAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        $alert = null;
        $request = $this->getRequest();

        if (0 != $request->request->get('id')) {
            $session = $this->get('request')->getSession();
            $doctrine = $this->getDoctrine();
            $entry = $doctrine->getRepository('NetresearchTimeTrackerBundle:Entry')
                ->find($request->request->get('id'));

            try {
                $this->deleteJiraWorklog($entry);
            } catch (AccessDeniedHttpException $e){
                // forward to jiraLogin if accesstoken is invalid
                $url = $this->generateUrl('hwi_oauth_service_redirect', array('service' => 'jira'));
                $message = $this->get('translator')->trans("Invalid Ticketsystem Token (Jira) you're going to be forwarded");
                return new ErrorResponse($message, 403, $url);
            }  catch (Exception $e){
                // Error on connecting Jira
                $alert = $e->getMessage() . '<br />'.
                    $this->get('translator')->trans("Dataset was deleted in Timetracker anyway");
            }

            // remember the day to calculate classes afterwards
            $day = $entry->getDay()->format("Y-m-d");

            $doctrine = $this->getDoctrine();
            $entityManager = $doctrine->getEntityManager();
            $entityManager->remove($entry);
            $entityManager->flush();

            // We have to update classes after deletion as well
            $this->calculateClasses($this->_getUserId(), $day);
        }

        return new Response(json_encode(array('success' => true, 'alert' => $alert)));
    }

    /**
     * Deletes a worklog entry in a remote JIRA installation.
     * JIRA instance is defined by ticketsystem in project.
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Entry
     *
     * @return void
     * @throws AccessDeniedHttpException
     */
    private function deleteJiraWorklog(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ) {
        $strTicket = $entry->getTicket();
        if (empty($strTicket)) {
            return;
        }

        if ((int) $entry->getWorklogId() <= 0) {
            return;
        }

        $project = $entry->getProject();
        if (! $project instanceof \Netresearch\TimeTrackerBundle\Entity\Project) {
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

        if (! $ticketSystem instanceof \Netresearch\TimeTrackerBundle\Entity\TicketSystem
            || false == $ticketSystem->getBookTime()
        ) {
            return;
        }

        /** @var $userTicketsystem UserTicketsystem */
        $userTicketsystem = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:UserTicketsystem')->findOneBy([
            'user' => $entry->getUser(),
            'ticketSystem' => $ticketSystem,
        ]);
        if ($userTicketsystem && $userTicketsystem->getAvoidConnection()) {
            return;
        }


        $jiraUserApi = new JiraUserApi($entry->getUser(), $ticketSystem, $this->container);
        $jiraUserApi->delete(sprintf(
            "/rest/api/2/issue/%s/worklog/%d",
            $strTicket,
            $entry->getWorklogId()
        ));

        $entry->setWorklogId(NULL);
    }

    private function calculateClasses($userId, $day)
    {
        if (! (int) $userId)
            return false;

        $doctrine = $this->getDoctrine();
        $entityManager = $doctrine->getEntityManager();
        $entries = $doctrine->getRepository('NetresearchTimeTrackerBundle:Entry')
            ->findByDay((int) $userId, $day);

        if (!count($entries)) {
            return false;
        }

        if (! is_object($entries[0])) {
            return false;
        }

        $entry = $entries[0];
        if ($entry->getClass() != Entry::CLASS_DAYBREAK) {
            $entry->setClass(Entry::CLASS_DAYBREAK);
            $entityManager->persist($entry);
            $entityManager->flush();
        }

        for ($c = 1; $c < count($entries); $c++) {
            $entry = $entries[$c];
            $previous = $entries[$c-1];

            if ($entry->getStart()->format("H:i") > $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_PAUSE) {
                    $entry->setClass(Entry::CLASS_PAUSE);
                    $entityManager->persist($entry);
                    $entityManager->flush();
                }
                continue;
            }

            if ($entry->getStart()->format("H:i") < $previous->getEnd()->format("H:i")) {
                if ($entry->getClass() != Entry::CLASS_OVERLAP) {
                    $entry->setClass(Entry::CLASS_OVERLAP);
                    $entityManager->persist($entry);
                    $entityManager->flush();
                }
                continue;
            }

            if ($entry->getClass() != Entry::CLASS_PLAIN) {
                $entry->setClass(Entry::CLASS_PLAIN);
                $entityManager->persist($entry);
                $entityManager->flush();
            }
        }

        return true;
    }

    public function saveAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        try {
            $alert = null;
            $this->logDataToFile($_POST, TRUE);

            $doctrine = $this->getDoctrine();
            $request = $this->getRequest()->request;

            if($request->get('id') != 0) {
                $entry = $doctrine->getRepository('NetresearchTimeTrackerBundle:Entry')->find($request->get('id'));
            } else {
                $entry = new Entry();
            }

            // We make a copy to determine if we have to update JIRA
            $oldEntry = clone $entry;

            if ($project = $doctrine->getRepository('NetresearchTimeTrackerBundle:Project')->find($request->get('project'))) {
                if (! $project->getActive()) {
                    $message = $this->get('translator')->trans("This project is inactive and cannot be used for booking.");
                    throw new \Exception($message);
                }
                $entry->setProject($project);
            }

            if ($customer = $doctrine->getRepository('NetresearchTimeTrackerBundle:Customer')->find($request->get('customer'))) {
                if (! $customer->getActive()) {
                    $message = $this->get('translator')->trans("This customer is inactive and cannot be used for booking.");
                    throw new \Exception($message);
                }
                $entry->setCustomer($customer);
            }

            // Retrieve user object
            $user = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')->find($this->_getUserId());
            $entry->setUser($user);

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
                ->calcDuration();

            // write log
            $this->logDataToFile($entry->toArray());

            // Check if the activity needs a ticket
            if (($user->getType() == 'DEV') && is_object($activity) && $activity->getNeedsTicket()) {
                if (strlen($entry->getTicket()) < 1) {
                    $message = $this->get('translator')->trans("For the activity '%activity%' you must specify a ticket.", array('%activity%' => $activity->getName()));
                    throw new \Exception($message);
                }
            }

            // check if ticket matches the project's ticket pattern
            $this->checkTicketFormat($entry->getTicket());

            // check if ticket matches the project's ticket pattern
            $this->checkJiraProjectMatch($entry->getProject(), $entry->getTicket());

            // update JIRA, if necessary
            try {
                $this->updateJiraWorklog($entry, $oldEntry);
            } catch (AccessDeniedHttpException $e){
                // forward to jiraLogin if accesstoken is invalid
                $url = $this->generateUrl('hwi_oauth_service_redirect', array('service' => 'jira'));
                $message = $this->get('translator')->trans("Invalid Ticketsystem Token (Jira) you're going to be forwarded");
                return new ErrorResponse($message, 403, $url);
            } catch (Exception $e){
                // Error on connecting Jira
                $alert = $e->getMessage() . '<br />'.
                    $this->get('translator')->trans("Dataset was modified in Timetracker anyway");
            }

            $em = $doctrine->getEntityManager();
            $em->persist($entry);
            $em->flush();
           
            try {
                $this->handleInternalTicketSystem($entry, $oldEntry);
            } catch (\Throwable $exception) {
                $alert = $exception->getMessage();
            } catch (\Exception $exception) {
                $alert = $exception->getMessage();
            }

            // we may have to update the classes of the entry's day
            if (is_object($entry->getDay())) {
                $this->calculateClasses($user->getId(), $entry->getDay()->format("Y-m-d"));
                // and the previous day, if the entry was moved
                if (is_object($oldEntry->getDay())) {
                    if ($entry->getDay()->format("Y-m-d") != $oldEntry->getDay()->format("Y-m-d"))
                        $this->calculateClasses($user->getId(), $oldEntry->getDay()->format("Y-m-d"));
                }
            }

            $response = array(
                'result' => $entry->toArray(),
                'alert'  => $alert
            );
            return new Response(json_encode($response));
        } catch (\Exception $e) {
            return new ErrorResponse($this->get('translator')->trans($e->getMessage()), 406);
        } catch (\Throwable $exception) {
            return new ErrorResponse($exception->getMessage(), 503);
        }
    }


    /**
     * Inserts a series of same entries by preset
     */
    public function bulkentryAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        try {
            $alert = null;
            $this->logDataToFile($_POST, TRUE);

            $doctrine = $this->getDoctrine();
            $request = $this->getRequest()->request;

            $preset = $doctrine->getRepository('NetresearchTimeTrackerBundle:Preset')->find((int) $request->get('preset'));
            if (! is_object($preset))
                throw new \Exception('Preset not found');

            // Retrieve needed objects
            $user     = $doctrine->getRepository('NetresearchTimeTrackerBundle:User')->find($this->_getUserId());
            $customer = $doctrine->getRepository('NetresearchTimeTrackerBundle:Customer')->find($preset->getCustomerId());
            $project  = $doctrine->getRepository('NetresearchTimeTrackerBundle:Project')->find($preset->getProjectId());
            $activity = $doctrine->getRepository('NetresearchTimeTrackerBundle:Activity')->find($preset->getActivityId());
            $em = $doctrine->getEntityManager();

            $date = new \DateTime($request->get('startdate'));
            $enddate = new \DateTime($request->get('enddate'));

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
                    && (in_array($date->format('w'), $weekend))) {
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

                if ($project)
                    $entry->setProject($project);
                if ($activity)
                    $entry->setActivity($activity);
                if ($customer)
                    $entry->setCustomer($customer);

                // write log
                $this->logDataToFile($entry->toArray());

                $em->persist($entry);
                $em->flush();

                // calculate color lines for the changed days
                $this->calculateClasses($user->getId(), $entry->getDay()->format("Y-m-d"));

                // print $date->format('d.m.Y') . " was saved.<br/>";
                $date->add(new \DateInterval('P1D'));
            } while ($date <= $enddate);

            $response = new Response($this->get('translator')->trans('All entries have been saved.'));
            $response->setStatusCode(200);
            return $response;

        } catch (\Exception $e) {
            $response = new Response($this->get('translator')->trans($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }
    }

    private function checkTicketFormat($ticket)
    {
        // do not check empty tickets
        if (strlen($ticket) < 1)
            return true;

        if (! TicketHelper::checkFormat($ticket)) {
            $message = $this->get('translator')->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        return true;
    }


    /**
     * TTT-199: check if ticket prefix matches project's jira id
     * @param Project $project
     * @param string $ticket
     * @return bool
     * @throws \Exception
     */
    private function checkJiraProjectMatch(Project $project, $ticket)
    {
        // do not check empty tickets
        if (strlen($ticket) < 1) {
            return true;
        }

        // do not check empty jira-projects
        if (strlen($project->getJiraId()) < 1)
            return true;

        if (! TicketHelper::checkFormat($ticket)) {
            $message = $this->get('translator')->trans("The ticket's format is not recognized.");
            throw new \Exception($message);
        }

        $jiraId = TicketHelper::getPrefix($ticket);
        $projectIds = explode(",", $project->getJiraId());

        $ok = false;
        foreach($projectIds AS $pId) {
            if (trim($pId) == $jiraId || $project->matchesInternalProject($jiraId)) {
                $ok = true;
            }
        }



        if (! $ok) {
            $message = $this->get('translator')->trans(
                "The ticket's JiraID '%ticket_jira_id%' does not match the project's Jira ID '%project_jira_id%'.",
                array('%ticket_jira_id%' => $jiraId, '%project_jira_id%' => $project->getJiraId())
            );
            throw new \Exception($message);
        }
    }

    private function logDataToFile(array $data, $raw = FALSE)
    {
        $file = $this->get('kernel')->getRootDir() . '/logs/' . self::LOG_FILE;
        if (!file_exists($file)) {
            if(!touch($file)) {
                throw new \Exception($this->get('translator')->trans('Could not create log file: %log_file%', array('%log_file%' => $file)));
            }
        }

        if (!is_writable($file)) {
            throw new \Exception($this->get('translator')->trans('Cannot write to log file: %log_file%', array('%log_file%' => $file)));
        }

        $log = sprintf('[%s][%s]: %s %s', date('d.m.Y H:i:s'), ($raw ? 'raw' : 'obj'), json_encode($data), PHP_EOL);
        file_put_contents($file, $log, FILE_APPEND);
    }



    /**
     * @param Entry $entry
     * @param Entry $oldEntry
     *
     * @return void
     * @throws AccessDeniedHttpException
     *
     * @todo avoid useless ws calls
     * @todo check ticket/worklog for existing before logging work
     */
    private function updateJiraWorklog(
        Entry $entry,
        Entry $oldEntry,
        TicketSystem $ticketSystem = null
    ){
        $project = $entry->getProject();
        if (! $project instanceof \Netresearch\TimeTrackerBundle\Entity\Project) {
            return;
        }

        if (empty($ticketSystem)) {
            $ticketSystem = $project->getTicketSystem();
        }
        if (! $ticketSystem instanceof \Netresearch\TimeTrackerBundle\Entity\TicketSystem
            || false == $ticketSystem->getBookTime()
        ) {
            return;
        }

        /** @var $userTicketsystem UserTicketsystem */
        $userTicketsystem = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:UserTicketsystem')->findOneBy([
            'user' => $entry->getUser(),
            'ticketSystem' => $ticketSystem,
        ]);
        if ($userTicketsystem && $userTicketsystem->getAvoidConnection()) {
            return;
        }

        $jiraUserApi = new JiraUserApi($entry->getUser(), $ticketSystem, $this->container);

        if ($this->shouldTicketBeDeleted($entry, $oldEntry)) {
            // ticket number changed
            // delete old worklog - new one will be created later
            $this->deleteJiraWorklog($oldEntry, $ticketSystem);
            $entry->setWorklogId(NULL);
        }

        if (!$entry->getDuration()) {
            // delete possible old worklog
            $this->deleteJiraWorklog($entry, $ticketSystem);
            // without duration we do not add any worklog as JIRA complains
            return;
        }

        $strTicket = $entry->getTicket();
        if (empty($strTicket)) {
            return;
        }

        $issue = $jiraUserApi->get(sprintf("/rest/api/2/issue/%s", $strTicket));


        if (isset($issue->errorMessages[0]) || $issue->key !== $strTicket) {
            // avoid logging work on non existent issues
            return;
        }

        if ($entry->getWorklogId()) {
            // check worklog entry for existance
            try {
                $worklog = $jiraUserApi->get(sprintf(
                    "/rest/api/2/issue/%s/worklog/%d", $strTicket,
                    $entry->getWorklogId()
                ));
            } catch (\Exception $e) {
                if (0 === strpos($e->getMessage(), 'JIRA says: Cannot find worklog with id')) {
                    $entry->setWorklogId(null);
                }
            }
        }

        // Calculate start date
        $startDate = $entry->getDay() ? $entry->getDay() : new \DateTime();
        if ($entry->getStart()) {
            $startDate->setTime(
                $entry->getStart()->format('H'), $entry->getStart()->format('i')
            );
        }
        //"2016-02-17T14:35:51.000+0100"
        $startDate = $startDate->format('Y-m-d\TH:i:s.000O');
        $activity = $entry->getActivity()? $entry->getActivity()->getName() : 'no activity specified';
        $description = !empty($entry->getDescription())? $entry->getDescription() : 'no description given';
        $comment = '#'.$entry->getId().': '.$activity .': '.$description;

        $arData = array(
            'comment' => $comment,
            'started' => $startDate,
            'timeSpentSeconds' => $entry->getDuration() * 60,
        );

        if ($entry->getWorklogId()) {
            // update old worklog entry
            $worklog = $jiraUserApi->put(
                sprintf("/rest/api/2/issue/%s/worklog/%d", $strTicket, $entry->getWorklogId()),
                $arData
            );
        } else {
            // create new worklog entry
            $worklog = $jiraUserApi->post(sprintf("/rest/api/2/issue/%s/worklog", $strTicket), $arData);
        }

        $entry->setWorklogId($worklog['id']);
    }


    /**
     * Creates an Ticket in the given ticketSystem
     *
     * @return array
     *
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-create-issue
     *
     */
    protected function createTicket(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ) {
        $jiraUserApi = new JiraUserApi($entry->getUser(), $ticketSystem, $this->container);

        $ticket = $jiraUserApi->post(
            sprintf("/rest/api/2/issue/"), $entry->getPostDataForInternalJiraTicketCreation()
        );

        return $ticket;
    }


    /**
     * Handles the entry for the configured internal ticketsystem.
     *
     * @param Entry $entry    the current entry
     * @param Entry $oldEntry the old entry
     *
     * @return void
     *
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-query-issues
     */
    protected  function handleInternalTicketSystem($entry, $oldEntry)
    {
        $project = $entry->getProject();

        $internalTicketSystem = $project->getInternalJiraTicketSystem();
        $internalProjectKey = $project->getInternalJiraProjectKey();

        // if we do not have an internal ticket system we could do nothing here
        if (empty($internalTicketSystem)) {
            return;
        }

        // if we do not have an internal project key, we can do nothing here
        if (empty($internalProjectKey)) {
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


        // get ticket system for internal worklog
        $internalTicketSystem = $this->getDoctrine()
                ->getRepository('NetresearchTimeTrackerBundle:TicketSystem')
                ->find($internalTicketSystem);

        // check if issue exist
        $jiraUserApi = new JiraUserApi($entry->getUser(), $internalTicketSystem, $this->container);
        // query for searching for existing internal ticket
        $issues = $jiraUserApi->get(sprintf(
            '/rest/api/2/search?maxResults=1&jql=project=%s%%20AND%%20summary~%s&fields=key,summary',
            $project->getInternalJiraProjectKey(),
            $strTicket
        ));

        $issueList = new Jira\Issues($issues);

        //issue already exists in internal jira
        if ($issueList->hasIssues()) {
            $issue = $issueList->first();
        } else {
            //issue does not exists, create it.
            $issue = $this->createTicket($entry, $internalTicketSystem);

            $issue = new Jira\Issue($issue);

            if ($issue->hasErrors()) {
                throw new \Exception(
                    'Failed creating internal JIRA ticket: '
                    . json_encode($issue->getErrorMessage())
                    . " | POST DATA: "
                    . json_encode(
                        $entry->getPostDataForInternalJiraTicketCreation()
                    )
                );
            }
        }

        $entry->setInternalJiraTicketOriginalKey(
            $strTicket
        );
        $entry->setTicket($issue->getKey());

        $oldEntry->setTicket($issue->getKey());

        $oldEntry->setInternalJiraTicketOriginalKey(
            $strOdlEntryTicket
        );

        $this->updateJiraWorklog(
            $entry,
            $oldEntry,
            $internalTicketSystem
        );
    }

    /**
     * Returns true, if the ticket should be deleted.
     *
     * @return bool
     */
    protected function shouldTicketBeDeleted($entry, $oldEntry)
    {
        $bDifferentTickets
            = $oldEntry->getTicket() != $entry->getTicket();
        $bIsCurrentTicketOriginalTicket
            = $entry->getInternalJiraTicketOriginalKey() === $entry->getTicket();

        return !$bIsCurrentTicketOriginalTicket && $bDifferentTickets;

    }
}
