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

        /** @var array<string, Customer> $customersByName reuse a resolved/created customer by name within the batch */
        $customersByName = [];
        /** @var array<string, Customer> $customersByTempoKey reuse a resolved/created customer by stable Tempo key within the batch */
        $customersByTempoKey = [];
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

            $customer = $this->resolveCustomer($row, $customersByName, $customersByTempoKey, $objectManager);

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
     * Override by id (existing customer, kept as-is) OR resolve-or-create by name,
     * carrying the row's optional stable Tempo key into the upsert (ADR-026 P2).
     *
     * An explicit $customer_id pick keeps its own identity — the Tempo key is NOT
     * applied to it, so choosing an existing customer never rebrands its key.
     *
     * @param array<string, Customer> $customersByName
     * @param array<string, Customer> $customersByTempoKey
     *
     * @throws InvalidArgumentException on an unknown id or a blank name with no id
     */
    private function resolveCustomer(ProjectImportConfirmRowDto $row, array &$customersByName, array &$customersByTempoKey, ObjectManager $objectManager): Customer
    {
        if (null !== $row->customer_id) {
            $customer = $this->customerRepository->find($row->customer_id);
            if (!$customer instanceof Customer) {
                throw new InvalidArgumentException(sprintf('Unknown customer id %d.', $row->customer_id));
            }

            return $customer;
        }

        $name = trim((string) $row->customer_name);
        if ('' === $name) {
            throw new InvalidArgumentException('A customer id or a non-empty customer name is required.');
        }

        $tempoKey = trim((string) $row->customer_key);
        $tempoKey = '' === $tempoKey ? null : $tempoKey;

        return $this->upsertCustomer($name, $tempoKey, $customersByName, $customersByTempoKey, $objectManager);
    }

    /**
     * Resolve-or-create a Customer from a name and an optional stable Tempo key,
     * making the Tempo->Customer mapping idempotent across runs (ADR-026 P2):
     *
     *  1. a key given & a customer already carries it -> reuse (name drift ignored);
     *  2. else match by name -> reuse, backfilling the key when the row had none;
     *  3. else create a new Customer with the name (+ the key when supplied).
     *
     * @param array<string, Customer> $customersByName     within-batch reuse by name
     * @param array<string, Customer> $customersByTempoKey within-batch reuse by key
     */
    private function upsertCustomer(string $name, ?string $tempoKey, array &$customersByName, array &$customersByTempoKey, ObjectManager $objectManager): Customer
    {
        if (null !== $tempoKey) {
            if (isset($customersByTempoKey[$tempoKey])) {
                return $customersByTempoKey[$tempoKey];
            }

            $byKey = $this->customerRepository->findOneByTempoCustomerKey($tempoKey);
            if ($byKey instanceof Customer) {
                $customersByTempoKey[$tempoKey] = $byKey;

                return $byKey;
            }
        }

        $customer = $customersByName[$name] ?? $this->customerRepository->findOneByName($name);
        if ($customer instanceof Customer) {
            $this->backfillTempoKey($customer, $tempoKey, $customersByTempoKey);
            $customersByName[$name] = $customer;

            return $customer;
        }

        $customer = new Customer();
        $customer->setName($name)
            ->setActive(true)
            ->setGlobal(false);
        if (null !== $tempoKey) {
            $customer->setTempoCustomerKey($tempoKey);
            $customersByTempoKey[$tempoKey] = $customer;
        }

        $objectManager->persist($customer);
        $customersByName[$name] = $customer;

        return $customer;
    }

    /**
     * Stamp the stable Tempo key onto a name-matched customer that has none, so a
     * later run resolves it by key. A customer already keyed keeps its key.
     *
     * @param array<string, Customer> $customersByTempoKey
     */
    private function backfillTempoKey(Customer $customer, ?string $tempoKey, array &$customersByTempoKey): void
    {
        if (null === $tempoKey) {
            return;
        }

        $existing = $customer->getTempoCustomerKey();
        if (null === $existing || '' === $existing) {
            $customer->setTempoCustomerKey($tempoKey);
        }

        $customersByTempoKey[$tempoKey] = $customer;
    }
}
