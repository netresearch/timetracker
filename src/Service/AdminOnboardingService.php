<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service;

use App\Dto\CustomerOnboardDto;
use App\Dto\CustomerSaveDto;
use App\Dto\ProjectOnboardDto;
use App\Dto\ProjectSaveDto;
use App\Dto\Response\CustomerDto;
use App\Dto\Response\ProjectDto;
use App\Dto\Response\UserDto;
use App\Dto\UserOnboardDto;
use App\Dto\UserSaveDto;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\UserType;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function array_unique;
use function array_values;
use function count;
use function implode;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Onboarding and offboarding of projects, customers and users (ADR-022
 * Phase 3) — one implementation behind the v2 admin endpoints and the MCP
 * admin tools.
 *
 * Creation is validated through the v1 *SaveDto constraint sets (unique
 * names, abbreviation format, team rules) — run explicitly, because
 * #[MapRequestPayload] validation only fires during argument resolution.
 * The entities are then built directly for the minimal onboarding surface;
 * the v1 Save*Action controllers keep owning the admin UI's full-featured
 * saves (services must not depend on controllers — architecture rule).
 * Offboarding toggles the active flag — records are never hard-deleted
 * (bookings reference them).
 */
final readonly class AdminOnboardingService
{
    public function __construct(
        private ManagerRegistry $managerRegistry,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @throws InvalidArgumentException on a validation failure
     */
    public function onboardProject(ProjectOnboardDto $projectOnboardDto): ProjectDto
    {
        $jiraId = strtoupper(trim($projectOnboardDto->jira_id));
        $this->assertValid(new ProjectSaveDto(
            name: trim($projectOnboardDto->name),
            customer: $projectOnboardDto->customer_id,
            jiraId: '' !== $jiraId ? $jiraId : null,
            active: true,
            global: $projectOnboardDto->global,
        ));

        $customer = $this->managerRegistry->getRepository(Customer::class)->find($projectOnboardDto->customer_id);
        if (!$customer instanceof Customer) {
            throw new InvalidArgumentException('Please choose a customer.');
        }

        $project = new Project();
        $project->setName(trim($projectOnboardDto->name))
            ->setCustomer($customer)
            ->setJiraId($jiraId)
            ->setActive(true)
            ->setGlobal($projectOnboardDto->global)
            ->setEstimation(0);

        $objectManager = $this->managerRegistry->getManager();
        $objectManager->persist($project);
        $objectManager->flush();

        return ProjectDto::fromEntity($project);
    }

    /**
     * @throws InvalidArgumentException on a validation failure
     */
    public function onboardCustomer(CustomerOnboardDto $customerOnboardDto): CustomerDto
    {
        $this->assertValid(new CustomerSaveDto(
            name: trim($customerOnboardDto->name),
            active: true,
            global: $customerOnboardDto->global,
            teams: $customerOnboardDto->team_ids,
        ));

        $customer = new Customer();
        $customer->setName(trim($customerOnboardDto->name))
            ->setActive(true)
            ->setGlobal($customerOnboardDto->global);
        foreach ($this->resolveTeams($customerOnboardDto->team_ids) as $team) {
            $customer->addTeam($team);
        }

        $objectManager = $this->managerRegistry->getManager();
        $objectManager->persist($customer);
        $objectManager->flush();

        return CustomerDto::fromEntity($customer);
    }

    /**
     * @throws InvalidArgumentException on a validation failure
     */
    public function onboardUser(UserOnboardDto $userOnboardDto): UserDto
    {
        // Runs the full UserSaveDto constraint set: unique username, valid and
        // unique abbreviation, and the at-least-one-team rule. No password —
        // an onboarded account authenticates against the directory (ADR-018).
        $this->assertValid(new UserSaveDto(
            username: trim($userOnboardDto->username),
            abbr: trim($userOnboardDto->abbr),
            type: $userOnboardDto->type,
            locale: $userOnboardDto->locale,
            active: true,
            teams: $userOnboardDto->team_ids,
        ));

        $user = new User();
        $user->setUsername(trim($userOnboardDto->username))
            ->setAbbr(trim($userOnboardDto->abbr))
            ->setType(UserType::from($userOnboardDto->type))
            ->setLocale($userOnboardDto->locale)
            ->setActive(true);
        foreach ($this->resolveTeams($userOnboardDto->team_ids) as $team) {
            $user->addTeam($team);
        }

        $objectManager = $this->managerRegistry->getManager();
        $objectManager->persist($user);
        $objectManager->flush();

        return UserDto::fromEntity($user);
    }

    public function setProjectActive(int $id, bool $active): ?ProjectDto
    {
        $project = $this->managerRegistry->getRepository(Project::class)->find($id);
        if (!$project instanceof Project) {
            return null;
        }

        $project->setActive($active);
        $this->managerRegistry->getManager()->flush();

        return ProjectDto::fromEntity($project);
    }

    public function setCustomerActive(int $id, bool $active): ?CustomerDto
    {
        $customer = $this->managerRegistry->getRepository(Customer::class)->find($id);
        if (!$customer instanceof Customer) {
            return null;
        }

        $customer->setActive($active);
        $this->managerRegistry->getManager()->flush();

        return CustomerDto::fromEntity($customer);
    }

    public function setUserActive(int $id, bool $active): ?UserDto
    {
        $user = $this->managerRegistry->getRepository(User::class)->find($id);
        if (!$user instanceof User) {
            return null;
        }

        $user->setActive($active);
        $this->managerRegistry->getManager()->flush();

        return UserDto::fromEntity($user);
    }

    /**
     * Explicitly runs the validator on a manually constructed v1 save DTO —
     * #[MapRequestPayload] constraint validation does not run outside argument
     * resolution, and skipping it would bypass uniqueness and format rules.
     *
     * @throws InvalidArgumentException with the first violation message
     */
    private function assertValid(object $saveDto): void
    {
        $violations = $this->validator->validate($saveDto);
        if (count($violations) > 0) {
            throw new InvalidArgumentException((string) $violations->get(0)->getMessage());
        }
    }

    /**
     * @param list<int> $teamIds
     *
     * @throws InvalidArgumentException when a requested team does not exist
     *
     * @return list<Team>
     */
    private function resolveTeams(array $teamIds): array
    {
        $teamIds = array_values(array_unique($teamIds));
        if ([] === $teamIds) {
            return [];
        }

        /** @var list<Team> $teams */
        $teams = $this->managerRegistry->getRepository(Team::class)->findBy(['id' => $teamIds]);
        if (count($teams) !== count($teamIds)) {
            throw new InvalidArgumentException(sprintf('Could not find team(s) with ID(s): %s.', implode(', ', $teamIds)));
        }

        return $teams;
    }
}
