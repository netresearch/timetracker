<?php declare(strict_types=1);

namespace App\Controller;

use Exception;
use Throwable;
use DateTime;
use DateInterval;
use App\Helper\JiraApiInvalidResourceException;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\User\Types;
use App\Response\Error;
use App\Helper\JiraApiException;
use App\Helper\JiraOAuthApi;
use App\Helper\TicketHelper;
use App\Model\Response;
use Symfony\Component\Routing\Annotation\Route;

class CrudController extends BaseController
{
    final public const LOG_FILE = 'trackingsave.log';

    #[Route(path: '/tracking/delete', name: 'timetracking_delete')]
    public function deleteAction(): Response
    {
        $alert = null;

        if (0 !== $this->request->get('id')) {
            /** @var Entry $entry */
            $entry = $this->doctrine->getRepository('App:Entry')
                ->find($this->request->get('id'))
            ;

            try {
                $this->deleteJiraWorklog($entry);
            } catch (JiraApiException $e) {
                if ($e->getRedirectUrl()) {
                    // Invalid JIRA token
                    return new Error($e->getMessage(), 403, $e->getRedirectUrl());
                }
                $alert = $e->getMessage().'<br />'.
                    $this->t('Dataset was modified in Timetracker anyway');
            }

            // remember the day to calculate classes afterwards
            $day = $entry->getDay()->format('Y-m-d');

            $manager = $this->doctrine->getManager();
            $manager->remove($entry);
            $manager->flush();

            // We have to update classes after deletion as well
            $this->calculateClasses($this->getUserId(), $day);
        }

        return new Response(json_encode(['success' => true, 'alert' => $alert], \JSON_THROW_ON_ERROR));
    }

    /**
     * Deletes a work log entry in a remote JIRA installation.
     * JIRA instance is defined by ticket system in project.
     *
     * @throws JiraApiException
     */
    private function deleteJiraWorklog(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ): void {
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            return;
        }

        if (empty($ticketSystem)) {
            $ticketSystem = $project->getTicketSystem();
        }

        if ($project->hasInternalJiraProjectKey()) {
            $ticketSystem = $this->doctrine
                ->getRepository('App:TicketSystem')
                ->find($project->getInternalJiraTicketSystem())
            ;
        }

        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || 'JIRA' !== $ticketSystem->getType()) {
            return;
        }

        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $ticketSystem,
            $this->doctrine,
            $this->container->get('router')
        );
        $jiraOAuthApi->deleteEntryJiraWorkLog($entry);
    }

    /**
     * Set rendering classes for pause, overlap and daybreak.
     *
     * @param int    $userId
     * @param string $day
     */
    private function calculateClasses(int $userId, string $day): void
    {
        if (!(int) $userId) {
            return;
        }

        $manager  = $this->doctrine->getManager();
        /** @var Entry[] $entries */
        $entries = $this->doctrine->getRepository('App:Entry')
            ->findByDay((int) $userId, $day)
        ;

        if (!(is_countable($entries) ? \count($entries) : 0)) {
            return;
        }

        if (!\is_object($entries[0])) {
            return;
        }

        $entry = $entries[0];
        if (Entry::CLASS_DAYBREAK !== $entry->getClass()) {
            $entry->setClass(Entry::CLASS_DAYBREAK);
            $manager->persist($entry);
            $manager->flush();
        }

        for ($c = 1; $c < (is_countable($entries) ? \count($entries) : 0); ++$c) {
            $entry    = $entries[$c];
            $previous = $entries[$c - 1];

            if ($entry->getStart()->format('H:i') > $previous->getEnd()->format('H:i')) {
                if (Entry::CLASS_PAUSE !== $entry->getClass()) {
                    $entry->setClass(Entry::CLASS_PAUSE);
                    $manager->persist($entry);
                    $manager->flush();
                }
                continue;
            }

            if ($entry->getStart()->format('H:i') < $previous->getEnd()->format('H:i')) {
                if (Entry::CLASS_OVERLAP !== $entry->getClass()) {
                    $entry->setClass(Entry::CLASS_OVERLAP);
                    $manager->persist($entry);
                    $manager->flush();
                }
                continue;
            }

            if (Entry::CLASS_PLAIN !== $entry->getClass()) {
                $entry->setClass(Entry::CLASS_PLAIN);
                $manager->persist($entry);
                $manager->flush();
            }
        }
    }

    /**
     * Save action handler.
     */
    #[Route(path: '/tracking/save', name: 'timetracking_save')]
    public function saveAction(): Error|Response
    {
        try {
            $alert = null;
            $this->logDataToFile($_POST, true);

            if (0 !== $this->request->get('id')) {
                $entry = $this->doctrine->getRepository('App:Entry')
                    ->find($this->request->get('id'))
                ;
            } else {
                $entry = new Entry();
            }

            // We make a copy to determine if we have to update JIRA
            $oldEntry = clone $entry;

            /** @var Project $project */
            if ($project = $this->doctrine->getRepository('App:Project')->find($this->request->get('project'))) {
                if (!$project->getActive()) {
                    $message = $this->t('This project is inactive and cannot be used for booking.');
                    throw new Exception($message);
                }
                $entry->setProject($project);
            }

            /** @var Customer $customer */
            if ($customer = $this->doctrine->getRepository('App:Customer')->find($this->request->get('customer'))) {
                if (!$customer->getActive()) {
                    $message = $this->t('This customer is inactive and cannot be used for booking.');
                    throw new Exception($message);
                }
                $entry->setCustomer($customer);
            }

            $user = $this->getWorkUser();
            $entry->setUser($user);

            $ticketSystem = $project->getTicketSystem();
            if (null !== $ticketSystem) {
                if (!$ticketSystem instanceof TicketSystem) {
                    $message = 'Einstellungen für das Ticket System überprüfen';

                    return $this->getFailedResponse($message, 400);
                }

                $jiraOAuthApi = new JiraOAuthApi(
                    $entry->getUser(),
                    $ticketSystem,
                    $this->doctrine,
                    $this->container->get('router')
                );

                if ('' !== $this->request->get('ticket')
                    && !$jiraOAuthApi->doesTicketExist($this->request->get('ticket'))
                ) {
                    $message = $this->request->get('ticket').' existiert nicht';
                    throw new Exception($message);
                }
            }

            /** @var Activity $activity */
            if ($activity = $this->doctrine->getRepository('App:Activity')->find($this->request->get('activity'))) {
                $entry->setActivity($activity);
            }

            $entry->setTicket(strtoupper(trim($this->request->get('ticket') ?: '')))
                ->setDescription($this->request->get('description') ?: '')
                ->setDay($this->request->get('date') ?: null)
                ->setStart($this->request->get('start') ?: null)
                ->setEnd($this->request->get('end') ?: null)
                ->setInternalJiraTicketOriginalKey($this->request->get('extTicket') ?: null)
                // ->calcDuration(is_object($activity) ? $activity->getFactor() : 1);
                ->calcDuration()
                ->setSyncedToTicketsystem(false)
            ;

            // write log
            $this->logDataToFile($entry->toArray());

            // Check if the activity needs a ticket
            if ((Types::DEV === $user->getType()) && \is_object($activity) && $activity->getNeedsTicket()) {
                if ($entry->getTicket() === '') {
                    $message = $this->t(
                        "For the activity '%activity%' you must specify a ticket.",
                        [
                            '%activity%' => $activity->getName(),
                        ]
                    );
                    throw new Exception($message);
                }
            }

            // check if ticket matches the project's ticket pattern
            $this->requireValidTicketFormat($entry->getTicket());

            // check if ticket matches the project's ticket pattern
            $this->requireValidTicketPrefix($entry->getProject(), $entry->getTicket());

            $em = $this->doctrine->getManager();
            $em->persist($entry);
            $em->flush();

            try {
                $this->handleInternalTicketSystem($entry, $oldEntry);
            } catch (Throwable $exception) {
                $alert = $exception->getMessage();
            }

            // we may have to update the classes of the entry's day
            if (\is_object($entry->getDay())) {
                $this->calculateClasses(
                    $user->getId(),
                    $entry->getDay()->format('Y-m-d')
                );
                // and the previous day, if the entry was moved
                if (\is_object($oldEntry->getDay())) {
                    if ($entry->getDay()->format('Y-m-d') !== $oldEntry->getDay()->format('Y-m-d')) {
                        $this->calculateClasses(
                            $user->getId(),
                            $oldEntry->getDay()->format('Y-m-d')
                        );
                    }
                }
            }

            // update JIRA, if necessary
            try {
                $this->updateJiraWorklog($entry, $oldEntry);
                // Save potential worklog ID
                $em->persist($entry);
                $em->flush();
            } catch (JiraApiException $e) {
                if ($e->getRedirectUrl()) {
                    // Invalid JIRA token
                    return new Error($e->getMessage(), 403, $e->getRedirectUrl());
                }
                $alert = $e->getMessage().'<br />'.
                    $this->t('Dataset was modified in Timetracker anyway');
            }

            $response = [
                'result' => $entry->toArray(),
                'alert'  => $alert,
            ];

            return new Response(json_encode($response, \JSON_THROW_ON_ERROR));
        } catch (Exception $e) {
            return new Error($this->t($e->getMessage()), 406);
        } catch (Throwable $exception) {
            return new Error($exception->getMessage(), 503);
        }
    }

    /**
     * Inserts a series of same entries by preset.
     */
    #[Route(path: '/tracking/bulkentry', name: 'timetracking_bulkentry')]
    public function bulkentryAction(): Response
    {
        try {
            $alert = null;
            $this->logDataToFile($_POST, true);

            $preset = $this->doctrine->getRepository('App:Preset')->find((int) $this->request->get('preset'));
            if (!\is_object($preset)) {
                throw new Exception('Preset not found');
            }

            // Retrieve needed objects
            /** @var User $user */
            $user = $this->getWorkUser();

            /** @var Customer $customer */
            $customer = $this->doctrine->getRepository('App:Customer')
                ->find($preset->getCustomerId())
            ;
            /** @var Project $project */
            $project = $this->doctrine->getRepository('App:Project')
                ->find($preset->getProjectId())
            ;
            /** @var Activity $activity */
            $activity = $this->doctrine->getRepository('App:Activity')
                ->find($preset->getActivityId())
            ;
            $em = $this->doctrine->getManager();

            $date    = new DateTime($this->request->get('startdate'));
            $endDate = new DateTime($this->request->get('enddate'));

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

            do {
                // some loop security
                ++$c;
                if ($c > 100) {
                    break;
                }

                // skip weekends
                if (($this->request->get('skipweekend'))
                    && (\in_array($date->format('w'), $weekend, true))
                ) {
                    $date->add(new DateInterval('P1D'));
                    continue;
                }

                // skip holidays
                if (($this->request->get('skipholidays'))) {
                    // skip regular holidays
                    if (\in_array($date->format('m-d'), $regular_holidays, true)) {
                        $date->add(new DateInterval('P1D'));
                        continue;
                    }

                    // skip irregular holidays
                    if (\in_array($date->format('Y-m-d'), $irregular_holidays, true)) {
                        $date->add(new DateInterval('P1D'));
                        continue;
                    }
                }

                $entry = new Entry();
                $entry->setUser($user)
                    ->setTicket('')
                    ->setDescription($preset->getDescription())
                    ->setDay($date)
                    ->setStart($this->request->get('starttime') ?: null)
                    ->setEnd($this->request->get('endtime') ?: null)
                    //->calcDuration(is_object($activity) ? $activity->getFactor() : 1);
                    ->calcDuration()
                ;

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
                $this->calculateClasses($user->getId(), $entry->getDay()->format('Y-m-d'));

                // print $date->format('d.m.Y') . " was saved.<br/>";
                $date->add(new DateInterval('P1D'));
            } while ($date <= $endDate);

            $response = new Response($this->t('All entries have been saved.'));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_OK);

            return $response;
        } catch (Exception $e) {
            $response = new Response($this->t($e->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }
    }

    /**
     * Ensures valid ticket number format.
     *
     * @param $ticket
     *
     * @throws Exception
     */
    private function requireValidTicketFormat($ticket): void
    {
        // do not check empty tickets
        if ($ticket === '') {
            return;
        }

        if (!TicketHelper::checkFormat($ticket)) {
            $message = $this->t("The ticket's format is not recognized.");
            throw new Exception($message);
        }
    }

    /**
     * TTT-199: check if ticket prefix matches project's Jira id.
     *
     * @param string $ticket
     *
     * @throws Exception
     */
    private function requireValidTicketPrefix(Project $project, string $ticket): void
    {
        // do not check empty tickets
        if ($ticket === '') {
            return;
        }

        // do not check empty jira-projects
        if ($project->getJiraId() === '') {
            return;
        }

        if (!TicketHelper::checkFormat($ticket)) {
            $message = $this->t("The ticket's format is not recognized.");
            throw new Exception($message);
        }

        $jiraId     = TicketHelper::getPrefix($ticket);
        $projectIds = explode(',', $project->getJiraId());

        foreach ($projectIds as $pId) {
            if (trim($pId) === $jiraId || $project->matchesInternalProject($jiraId)) {
                return;
            }
        }

        $message = $this->t(
            "The ticket's Jira ID '%ticket_jira_id%' does not match the project's Jira ID '%project_jira_id%'.",
            ['%ticket_jira_id%' => $jiraId, '%project_jira_id%' => $project->getJiraId()]
        );

        throw new Exception($message);
    }

    /**
     * Write log entry to log file.
     *
     * @throws Exception
     *
     * @deprecated
     */
    private function logDataToFile(array $data, bool $raw = false): void
    {
        return;

        $file = $this->getParameter('kernel.root_dir').'/logs/'.self::LOG_FILE;
        if (!file_exists($file) && !touch($file)) {
            throw new Exception($this->t('Could not create log file: %log_file%', ['%log_file%' => $file]));
        }

        if (!is_writable($file)) {
            throw new Exception($this->t('Cannot write to log file: %log_file%', ['%log_file%' => $file]));
        }

        $log = sprintf(
            '[%s][%s]: %s %s',
            date('d.m.Y H:i:s'),
            ($raw ? 'raw' : 'obj'),
            json_encode($data, \JSON_THROW_ON_ERROR),
            \PHP_EOL
        );

        file_put_contents($file, $log, \FILE_APPEND);
    }

    /**
     * Updates a JIRA work log entry.
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    private function updateJiraWorklog(
        Entry $entry,
        Entry $oldEntry,
        TicketSystem $ticketSystem = null
    ): void {
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            return;
        }

        if (empty($ticketSystem)) {
            $ticketSystem = $project->getTicketSystem();
        }
        if (!$ticketSystem instanceof TicketSystem) {
            return;
        }

        if (!$ticketSystem->getBookTime() || 'JIRA' !== $ticketSystem->getType()) {
            return;
        }

        if ($this->shouldTicketBeDeleted($entry, $oldEntry)) {
            // ticket number changed
            // delete old worklog - new one will be created later
            $this->deleteJiraWorklog($oldEntry, $ticketSystem);
            $entry->setWorklogId(null);
        }

        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $ticketSystem,
            $this->doctrine,
            $this->container->get('router')
        );
        $jiraOAuthApi->updateEntryJiraWorkLog($entry);
    }

    /**
     * Creates an Ticket in the given ticketSystem.
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     *
     * @return string
     *
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-create-issue
     */
    protected function createTicket(
        Entry $entry,
        TicketSystem $ticketSystem = null
    ): string {
        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $ticketSystem,
            $this->doctrine,
            $this->container->get('router')
        );

        return $jiraOAuthApi->createTicket($entry);
    }

    /**
     * Handles the entry for the configured internal ticketsystem.
     *
     * @param Entry $entry    the current entry
     * @param Entry $oldEntry the old entry
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     *
     * @see https://developer.atlassian.com/jiradev/jira-apis/jira-rest-apis/jira-rest-api-tutorials/jira-rest-api-example-query-issues
     */
    protected function handleInternalTicketSystem(Entry $entry, Entry $oldEntry): void
    {
        $project = $entry->getProject();

        $internalTicketSystem = $project->getInternalJiraTicketSystem();
        $internalProjectKey   = $project->getInternalJiraProjectKey();

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

        // get ticket system for internal work log
        /** @var TicketSystem $internalTicketSystem */
        $internalTicketSystem = $this->doctrine
            ->getRepository('App:TicketSystem')
            ->find($internalTicketSystem)
        ;

        // check if issue exist
        $jiraOAuthApi = new JiraOAuthApi(
            $entry->getUser(),
            $internalTicketSystem,
            $this->doctrine,
            $this->container->get('router')
        );
        $searchResult = $jiraOAuthApi->searchTicket(
            sprintf(
                'project = %s AND summary ~ %s',
                $project->getInternalJiraProjectKey(),
                $strTicket
            ),
            'key,summary',
            1
        );

        //issue already exists in internal jira
        if (is_countable($searchResult->issues) ? \count($searchResult->issues) : 0) {
            $issue = reset($searchResult->issues);
        } else {
            //issue does not exists, create it.
            $issue = $this->createTicket($entry, $internalTicketSystem);
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
            $internalTicketSystem
        );
    }

    /**
     * Returns true, if the ticket should be deleted.
     *
     * @return bool
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
