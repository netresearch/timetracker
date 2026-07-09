<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Preset;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Mcp\Exception\ToolCallException;

use function ctype_digit;
use function sprintf;
use function trim;

/**
 * Resolves the admin tools' name-or-id inputs to entities (ADR-022 Phase 3) —
 * a coding agent knows names ("ACME", "jane.doe"), not the SPA's numeric ids.
 */
final readonly class AdminEntityResolver
{
    public function __construct(private ManagerRegistry $managerRegistry)
    {
    }

    /**
     * @throws ToolCallException when the project cannot be resolved
     */
    public function project(string $input): Project
    {
        $project = $this->find(Project::class, $input, 'name');
        if (!$project instanceof Project) {
            throw new ToolCallException(sprintf('Unknown project "%s" — use list_projects to see valid names/ids.', trim($input)));
        }

        return $project;
    }

    /**
     * @throws ToolCallException when the activity cannot be resolved
     */
    public function activity(string $input): Activity
    {
        $activity = $this->find(Activity::class, $input, 'name');
        if (!$activity instanceof Activity) {
            throw new ToolCallException(sprintf('Unknown activity "%s" — use list_activities to see valid names/ids.', trim($input)));
        }

        return $activity;
    }

    /**
     * @throws ToolCallException when the preset cannot be resolved
     */
    public function preset(string $input): Preset
    {
        $preset = $this->find(Preset::class, $input, 'name');
        if (!$preset instanceof Preset) {
            throw new ToolCallException(sprintf('Unknown preset "%s" — use list_presets to see valid names/ids.', trim($input)));
        }

        return $preset;
    }

    /**
     * @throws ToolCallException when the customer cannot be resolved
     */
    public function customer(string $input): Customer
    {
        $customer = $this->find(Customer::class, $input, 'name');
        if (!$customer instanceof Customer) {
            throw new ToolCallException(sprintf('Unknown customer "%s".', trim($input)));
        }

        return $customer;
    }

    /**
     * @throws ToolCallException when the user cannot be resolved
     */
    public function user(string $input): User
    {
        $user = $this->find(User::class, $input, 'username');
        if (!$user instanceof User) {
            throw new ToolCallException(sprintf('Unknown user "%s".', trim($input)));
        }

        return $user;
    }

    /**
     * @param class-string $entityClass
     */
    private function find(string $entityClass, string $input, string $nameField): ?object
    {
        $input = trim($input);
        $repository = $this->managerRegistry->getRepository($entityClass);

        return ctype_digit($input)
            ? $repository->find((int) $input)
            : $repository->findOneBy([$nameField => $input]);
    }
}
