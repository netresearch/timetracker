<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Personio;

use App\DTO\Personio\EmployeeMatch;
use App\Entity\User;

use function count;
use function mb_strtolower;
use function sprintf;
use function strstr;
use function trim;

/**
 * Proposes TT user -> Personio employee-id matches (ADR-024 P3).
 *
 * Two strategies, in order of confidence: the Personio e-mail localpart equals
 * the TT username, or the `firstname.lastname` of the person equals it (the
 * company's username scheme). A username that matches exactly one person is a
 * proposal; zero or several (ambiguous) matches are skipped — never guessed.
 * Pure and side-effect free: it neither reads nor writes the database.
 */
final readonly class EmployeeMatcher
{
    /**
     * @param list<User>                                                                       $users   TT users to match (typically those without an employee id yet)
     * @param list<array{id: string, first_name: ?string, last_name: ?string, email: ?string}> $persons Personio persons ({@see PersonioClient::listPersons()})
     *
     * @return list<EmployeeMatch>
     */
    public function match(array $users, array $persons): array
    {
        $matches = [];
        foreach ($users as $user) {
            $username = mb_strtolower(trim((string) $user->getUsername()));
            if ('' === $username) {
                continue;
            }

            $candidates = $this->candidatesFor($username, $persons);
            // Exactly one distinct person -> a confident proposal; 0 or >1 -> skip.
            if (1 !== count($candidates)) {
                continue;
            }

            $candidate = array_first($candidates);
            $matches[] = new EmployeeMatch(
                (int) $user->getId(),
                (string) $user->getUsername(),
                $candidate['id'],
                $candidate['name'],
                $candidate['source'],
            );
        }

        return $matches;
    }

    /**
     * Persons matching $username, keyed by person id (deduped). E-mail wins over
     * name as the recorded source when a person matches both ways.
     *
     * @param list<array{id: string, first_name: ?string, last_name: ?string, email: ?string}> $persons
     *
     * @return array<string, array{id: string, name: string, source: string}>
     */
    private function candidatesFor(string $username, array $persons): array
    {
        $candidates = [];
        foreach ($persons as $person) {
            $id = $person['id'];
            if ('' === $id) {
                continue;
            }

            $source = $this->matchSource($username, $person);
            if (null === $source) {
                continue;
            }

            // First match for a person wins; e-mail (checked first) beats name.
            $candidates[$id] ??= [
                'id' => $id,
                'name' => trim(sprintf('%s %s', $person['first_name'] ?? '', $person['last_name'] ?? '')),
                'source' => $source,
            ];
        }

        return $candidates;
    }

    /**
     * How $username matches $person, or null. E-mail localpart first (reliable),
     * then the firstname.lastname scheme.
     *
     * @param array{id: string, first_name: ?string, last_name: ?string, email: ?string} $person
     */
    private function matchSource(string $username, array $person): ?string
    {
        $email = $person['email'];
        if (null !== $email && '' !== $email) {
            $before = strstr($email, '@', true);
            $localpart = mb_strtolower(trim(false === $before ? $email : $before));
            if ($localpart === $username) {
                return 'email';
            }
        }

        $first = mb_strtolower(trim((string) $person['first_name']));
        $last = mb_strtolower(trim((string) $person['last_name']));
        if ('' !== $first && '' !== $last && sprintf('%s.%s', $first, $last) === $username) {
            return 'name';
        }

        return null;
    }
}
