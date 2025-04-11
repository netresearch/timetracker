<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Service\Integration\Jira\WorklogService;
use App\Service\TimeEntry\TimeEntryService;
use App\Service\Ticket\TicketValidationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller for time entry management.
 */
class TimeEntryController extends BaseController
{
    /**
     * @var TimeEntryService
     */
    private $timeEntryService;

    /**
     * @var WorklogService
     */
    private $worklogService;

    /**
     * @var TicketValidationService
     */
    private $ticketValidationService;

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setTimeEntryService(TimeEntryService $timeEntryService): void
    {
        $this->timeEntryService = $timeEntryService;
    }

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setWorklogService(WorklogService $worklogService): void
    {
        $this->worklogService = $worklogService;
    }

    /**
     * @required
     * @codeCoverageIgnore
     */
    public function setTicketValidationService(TicketValidationService $ticketValidationService): void
    {
        $this->ticketValidationService = $ticketValidationService;
    }

    /**
     * Delete a time entry.
     *
     * @Route("/crud/delete", name="entry_delete", methods={"POST"})
     */
    public function deleteAction(Request $request): Response|Error|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $alert = null;

        if (0 != $request->request->get('id')) {
            $doctrine = $this->getDoctrine();
            /** @var \App\Repository\EntryRepository $entryRepo */
            $entryRepo = $doctrine->getRepository(Entry::class);
            /** @var Entry $entry */
            $entry = $entryRepo->find($request->request->get('id'));

            if (!$entry) {
                $message = $this->translator->trans('No entry for id.');
                return new Error($message, 404);
            }

            try {
                $result = $this->timeEntryService->deleteEntry($entry);
                return new JsonResponse($result);
            } catch (JiraApiUnauthorizedException $e) {
                // Invalid JIRA token
                return new Error($e->getMessage(), 403, $e->getRedirectUrl());
            } catch (JiraApiException $e) {
                $alert = $e->getMessage() . '<br />' .
                    $this->translator->trans("Dataset was modified in Timetracker anyway");
            }
        }

        return new JsonResponse(['success' => true, 'alert' => $alert]);
    }

    /**
     * Save a time entry.
     *
     * @Route("/crud/save", name="entry_save", methods={"POST"})
     */
    public function saveAction(Request $request): Response|JsonResponse|Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            // Currently this is just a placeholder method
            // We would extract the save functionality from the CrudController here

            $message = "Save action not yet implemented in TimeEntryController";
            return new JsonResponse(['message' => $message]);
        } catch (\Exception $e) {
            $message = $this->translator->trans('An error occurred while saving the entry: %error%', ['%error%' => $e->getMessage()]);
            return new Error($message, 500);
        }
    }

    /**
     * Process bulk time entries.
     *
     * @Route("/crud/bulkentry", name="entry_bulk_save", methods={"POST"})
     */
    public function bulkentryAction(Request $request): Response|JsonResponse|Error
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            // Currently this is just a placeholder method
            // We would extract the bulk entry functionality from the CrudController here

            $message = "Bulk entry action not yet implemented in TimeEntryController";
            return new JsonResponse(['message' => $message]);
        } catch (\Exception $e) {
            $message = $this->translator->trans('An error occurred while processing bulk entries: %error%', ['%error%' => $e->getMessage()]);
            return new Error($message, 500);
        }
    }
}
