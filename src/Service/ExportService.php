<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Entry;
use App\Enum\TicketSystemType;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Doctrine\Persistence\ManagerRegistry;

use function in_array;
use function is_array;
use function is_object;
use function is_string;

class ExportService
{
    public function __construct(private readonly ManagerRegistry $managerRegistry, private readonly JiraOAuthApiFactory $jiraOAuthApiFactory)
    {
    }

    /**
     * Returns entries filtered and ordered.
     *
     * @param array<string, bool>|null $arSort
     *
     * @return array<string, mixed>
     */
    public function getEntries(\App\Entity\User $currentUser, array $arSort = null, string $strStart = '', string $strEnd = '', array $arProjects = null, array $arUsers = null): array
    {
        /** @var \App\Repository\EntryRepository $entryRepo */
        $entryRepo = $this->managerRegistry->getRepository(\App\Entity\Entry::class);

        $arFilter = [];

        if ('' !== $strStart) {
            $arFilter['start'] = $strStart;
        }

        if ('' !== $strEnd) {
            $arFilter['end'] = $strEnd;
        }

        if (is_array($arProjects) && [] !== $arProjects) {
            $arFilter['projects'] = $arProjects;
        }

        if (is_array($arUsers) && [] !== $arUsers) {
            $arFilter['users'] = $arUsers;
        }

        $arEntries = $entryRepo->getEntries(
            $currentUser->getId(),
            $arSort,
            $arFilter,
        );

        $arApi = [];

        $arReturn = [];
        foreach ($arEntries as $entry) {
            if (!$entry instanceof Entry) {
                continue;
            }

            $arReturn[] = [
                'id' => $entry->getId(),
                'user' => $entry->getUser() ? $entry->getUser()->getName() : '',
                'customer' => $entry->getCustomer() ? $entry->getCustomer()->getName() : '',
                'project' => $entry->getProject() ? $entry->getProject()->getName() : '',
                'activity' => $entry->getActivity() ? $entry->getActivity()->getName() : '',
                'description' => $entry->getDescription(),
                'start' => $entry->getStart() ? $entry->getStart()->format('Y-m-d H:i:s') : '',
                'end' => $entry->getEnd() ? $entry->getEnd()->format('Y-m-d H:i:s') : '',
                'ticket' => $entry->getTicket(),
                'ticket_url' => $this->getTicketUrl($entry),
                'worklog_url' => $this->getWorklogUrl($entry, $arApi, $currentUser),
            ];
        }

        return $arReturn;
    }

    private function getTicketUrl(Entry $entry): string
    {
        if ('' === $entry->getTicket()) {
            return '';
        }

        $project = $entry->getProject();
        if (!$project) {
            return '';
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem) {
            return '';
        }

        if ('' !== $entry->getTicket()
            && $entry->getProject()
            && $entry->getProject()->getTicketSystem()
            && $entry->getProject()->getTicketSystem()->getBookTime()
            && TicketSystemType::JIRA === $entry->getProject()->getTicketSystem()->getType()
        ) {
            $ticketSystem = $entry->getProject()->getTicketSystem();

            if (!isset($arApi[$ticketSystem->getId()])) {
                $arApi[$ticketSystem->getId()] = $this->jiraOAuthApiFactory->create($currentUser, $ticketSystem);
            }

            if (isset($arApi[$ticketSystem->getId()])) {
                return $arApi[$ticketSystem->getId()]->getTicketUrl($entry->getTicket());
            }
        }

        return sprintf($ticketSystem->getTicketUrl(), $entry->getTicket());
    }

    /**
     * @param array<int, mixed> $arApi
     */
    private function getWorklogUrl(Entry $entry, array &$arApi, \App\Entity\User $currentUser): string
    {
        if ('' === $entry->getTicket() || !$entry->getWorklogId()) {
            return '';
        }

        $project = $entry->getProject();
        if (!$project) {
            return '';
        }

        $ticketSystem = $project->getTicketSystem();
        if (!$ticketSystem) {
            return '';
        }

        if (!$ticketSystem->getBookTime() || TicketSystemType::JIRA !== $ticketSystem->getType()) {
            return '';
        }

        if (!isset($arApi[$ticketSystem->getId()])) {
            $arApi[$ticketSystem->getId()] = $this->jiraOAuthApiFactory->create($currentUser, $ticketSystem);
        }

        if (!isset($arApi[$ticketSystem->getId()])) {
            return '';
        }

        return $arApi[$ticketSystem->getId()]->getWorklogUrl($entry->getTicket(), $entry->getWorklogId());
    }
}