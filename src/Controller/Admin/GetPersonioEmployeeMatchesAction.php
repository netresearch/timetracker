<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\DTO\Personio\EmployeeMatch;
use App\Entity\PersonioConfig;
use App\Repository\PersonioConfigRepository;
use App\Repository\UserRepository;
use App\Service\Personio\EmployeeMatcher;
use App\Service\Personio\PersonioClientFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

use function array_map;

/**
 * Review screen backend (ADR-024 P3): list the proposed TT user -> Personio
 * employee-id matches for the users that have no id yet, from the Persons API.
 * Read-only — the admin confirms each row via the confirm endpoint. ROLE_ADMIN
 * gates admin and PL alike here (a PL carries ROLE_ADMIN, v4 compat).
 */
final readonly class GetPersonioEmployeeMatchesAction
{
    public function __construct(
        private PersonioConfigRepository $configRepository,
        private UserRepository $userRepository,
        private PersonioClientFactory $clientFactory,
        private EmployeeMatcher $employeeMatcher,
    ) {
    }

    #[Route(path: '/personio/employee-matches', name: 'personio_employee_matches', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): JsonResponse
    {
        $config = $this->configRepository->findActive();
        if (!$config instanceof PersonioConfig) {
            return new JsonResponse(['message' => 'No active Personio configuration.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $users = $this->userRepository->findWithoutPersonioEmployeeId();
        if ([] === $users) {
            return new JsonResponse(['proposals' => []]);
        }

        try {
            $persons = $this->clientFactory->create($config)->listPersons();
        } catch (Throwable) {
            return new JsonResponse(['message' => 'Could not reach Personio to list persons.'], Response::HTTP_BAD_GATEWAY);
        }

        $matches = $this->employeeMatcher->match($users, $persons);

        return new JsonResponse(['proposals' => array_map($this->toArray(...), $matches)]);
    }

    /**
     * @return array{user_id: int, username: string, person_id: string, person_name: string, source: string}
     */
    private function toArray(EmployeeMatch $employeeMatch): array
    {
        return [
            'user_id' => $employeeMatch->userId,
            'username' => $employeeMatch->username,
            'person_id' => $employeeMatch->personId,
            'person_name' => $employeeMatch->personName,
            'source' => $employeeMatch->source,
        ];
    }
}
