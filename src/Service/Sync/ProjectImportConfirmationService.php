<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\Dto\ProjectImportConfirmDto;
use App\Dto\ProjectImportConfirmRowDto;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use InvalidArgumentException;

use function preg_match;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Persists the ADR-026 P1 project-import confirmations: for each confirmed row
 * it resolves (or creates) the Customer and creates the TT Project with its
 * Jira key as jira_id. Idempotent — a prefix a project already owns on that
 * ticket system is linked, not duplicated (mirrors AdminOnboardingService's
 * direct-persist style; services must not depend on controllers).
 *
 * All rows are validated before anything is written, then flushed once, so a
 * bad row rejects the whole batch instead of leaving a partial import.
 */
final readonly class ProjectImportConfirmationService
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private ProjectRepository $projectRepository,
        private CustomerRepository $customerRepository,
    ) {
    }

    /**
     * @throws InvalidArgumentException on the first invalid row
     *
     * @return list<array{jira_key: string, project_id: int|null, project_name: string|null, customer_id: int|null, customer_name: string|null, ticket_system_id: int|null, status: string}>
     */
    public function confirm(ProjectImportConfirmDto $projectImportConfirmDto): array
    {
        $objectManager = $this->managerRegistry->getManager();

        /** @var array<string, Customer> $newCustomersByName reuse a to-be-created customer within the batch */
        $newCustomersByName = [];
        /** @var array<string, Project> $projectsByKey reuse a to-be-created project within the batch */
        $projectsByKey = [];

        /** @var list<array{project: Project, ticketSystem: TicketSystem, prefix: string, status: string}> $plan */
        $plan = [];

        foreach ($projectImportConfirmDto->rows as $row) {
            $prefix = $this->normalizePrefix($row->jira_key);
            $ticketSystem = $this->resolveTicketSystem($row->ticket_system_id);

            // Idempotency first: a prefix a project already owns is linked, so no
            // Customer is resolved or created for it (its existing link stands).
            $key = sprintf('%d:%s', $ticketSystem->getId(), $prefix);
            $existing = $projectsByKey[$key] ?? $this->projectRepository->findOneByJiraIdAndTicketSystem($prefix, $ticketSystem);
            if ($existing instanceof Project) {
                $plan[] = ['project' => $existing, 'ticketSystem' => $ticketSystem, 'prefix' => $prefix, 'status' => 'existing'];

                continue;
            }

            $customer = $this->resolveCustomer($row, $newCustomersByName, $objectManager);

            $project = new Project();
            $project->setName(trim($row->project_name))
                ->setCustomer($customer)
                ->setJiraId($prefix)
                ->setTicketSystem($ticketSystem)
                ->setActive(true)
                ->setGlobal(false)
                ->setEstimation(0);
            $objectManager->persist($project);
            $projectsByKey[$key] = $project;

            $plan[] = ['project' => $project, 'ticketSystem' => $ticketSystem, 'prefix' => $prefix, 'status' => 'created'];
        }

        $objectManager->flush();

        $results = [];
        foreach ($plan as $entry) {
            $customer = $entry['project']->getCustomer();
            $results[] = [
                'jira_key' => $entry['prefix'],
                'project_id' => $entry['project']->getId(),
                'project_name' => $entry['project']->getName(),
                'customer_id' => $customer?->getId(),
                'customer_name' => $customer?->getName(),
                'ticket_system_id' => $entry['ticketSystem']->getId(),
                'status' => $entry['status'],
            ];
        }

        return $results;
    }

    /**
     * @throws InvalidArgumentException when the key is not a single valid Jira prefix
     */
    private function normalizePrefix(string $jiraKey): string
    {
        $prefix = strtoupper(trim($jiraKey));
        // A single Jira project key: a capital letter followed by capitals/digits.
        // Rejects blanks, comma-lists and lower-case junk (the import writes one
        // prefix per project — a list would be ambiguous).
        if (1 !== preg_match('/^[A-Z][A-Z0-9]*$/', $prefix)) {
            throw new InvalidArgumentException(sprintf('Invalid Jira prefix: "%s".', $jiraKey));
        }

        return $prefix;
    }

    /**
     * @throws InvalidArgumentException when the ticket system does not exist
     */
    private function resolveTicketSystem(int $ticketSystemId): TicketSystem
    {
        $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($ticketSystemId);
        if (!$ticketSystem instanceof TicketSystem) {
            throw new InvalidArgumentException(sprintf('Unknown ticket system id %d.', $ticketSystemId));
        }

        return $ticketSystem;
    }

    /**
     * Override by id (existing customer) OR resolve by name (found, else created).
     *
     * @param array<string, Customer> $newCustomersByName
     *
     * @throws InvalidArgumentException on an unknown id or a blank name with no id
     */
    private function resolveCustomer(ProjectImportConfirmRowDto $row, array &$newCustomersByName, ObjectManager $objectManager): Customer
    {
        if (null !== $row->customer_id) {
            $customer = $this->customerRepository->findOneById($row->customer_id);
            if (!$customer instanceof Customer) {
                throw new InvalidArgumentException(sprintf('Unknown customer id %d.', $row->customer_id));
            }

            return $customer;
        }

        $name = trim((string) $row->customer_name);
        if ('' === $name) {
            throw new InvalidArgumentException('A customer id or a non-empty customer name is required.');
        }

        if (isset($newCustomersByName[$name])) {
            return $newCustomersByName[$name];
        }

        $customer = $this->customerRepository->findOneByName($name);
        if ($customer instanceof Customer) {
            return $customer;
        }

        $customer = new Customer();
        $customer->setName($name)
            ->setActive(true)
            ->setGlobal(false);
        $objectManager->persist($customer);
        $newCustomersByName[$name] = $customer;

        return $customer;
    }
}
