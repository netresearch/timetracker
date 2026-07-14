<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function ctype_digit;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;

/**
 * Persist the admin-confirmed ADR-024 P3 employee-id matches: for each confirmed
 * {user_id, person_id} the user's Personio employee id is set. A non-numeric
 * Personio id or an unknown user is skipped (reported in the result), never
 * written wrong. ROLE_ADMIN gated; validated then flushed once.
 */
final readonly class ConfirmPersonioEmployeeMatchesAction
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '/personio/employee-matches/confirm', name: 'personio_employee_matches_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request): JsonResponse
    {
        $content = $request->getContent();
        $payload = json_decode('' !== $content ? $content : '{}', true);
        $matches = is_array($payload) && is_array($payload['matches'] ?? null) ? $payload['matches'] : [];

        $applied = [];
        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $userId = $match['user_id'] ?? null;
            $personId = $match['person_id'] ?? null;
            // Personio employee ids are numeric (the column is a bigint); a
            // non-numeric id is skipped rather than truncated to a wrong value.
            if (!is_int($userId)) {
                continue;
            }
            if (!is_string($personId)) {
                continue;
            }
            if (!ctype_digit($personId)) {
                continue;
            }

            $user = $this->userRepository->find($userId);
            if (!$user instanceof User) {
                continue;
            }

            $user->setPersonioEmployeeId((int) $personId);
            $applied[] = ['user_id' => $userId, 'username' => (string) $user->getUsername(), 'person_id' => $personId];
        }

        $this->entityManager->flush();

        return new JsonResponse(['applied' => $applied], Response::HTTP_OK);
    }
}
