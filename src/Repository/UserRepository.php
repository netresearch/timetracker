<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class UserRepository.
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    use LastActivityTrait;

    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, User::class);
    }

    /**
     * Priority 2: Add explicit type-safe repository method for mixed type handling.
     */
    public function findOneById(int $id): ?User
    {
        $result = $this->find($id);

        return $result instanceof User ? $result : null;
    }

    /**
     * Find a user by username.
     */
    public function findOneByUsername(string $username): ?User
    {
        $result = $this->findOneBy(['username' => $username]);

        return $result instanceof User ? $result : null;
    }

    /**
     * @return array<int, array{user: array{id:int, username:string, type:string, abbr:string, locale:string}}>
     */
    public function getUsers(int $currentUserId): array
    {
        /** @var User[] $users */
        $users = $this->findBy(
            [],
            ['username' => 'ASC'],
        );

        $data = [];

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if ($currentUserId === $user->getId()) {
                // Set current user on top
                array_unshift($data, ['user' => [
                    'id' => $user->getId(),
                    'username' => (string) $user->getUsername(),
                    'type' => $user->getType()->value,
                    'abbr' => (string) $user->getAbbr(),
                    'locale' => $user->getLocale(),
                ]]);
            } else {
                $data[] = ['user' => [
                    'id' => (int) $user->getId(),
                    'username' => (string) $user->getUsername(),
                    'type' => $user->getType()->value,
                    'abbr' => (string) $user->getAbbr(),
                    'locale' => $user->getLocale(),
                ]];
            }
        }

        return $data;
    }

    /**
     * @return array<int, array{user: array{id:int, username:string, type:string, abbr:string, abbr_duplicate: bool, locale:string, teams: array<int, int>, active: bool, last_activity: string|null}}>
     */
    public function getAllUsers(): array
    {
        /** @var User[] $users */
        $users = $this->findBy(
            [],
            ['username' => 'ASC'],
        );

        $lastActivity = $this->lastActivityBy('user_id');

        // Count each non-empty abbreviation so the admin grid can flag the legacy
        // duplicates that need cleaning up (the save validator grandfathers them).
        $abbrCounts = [];
        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $abbr = (string) $user->getAbbr();
            if ('' !== $abbr) {
                $abbrCounts[$abbr] = ($abbrCounts[$abbr] ?? 0) + 1;
            }
        }

        $data = [];
        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $teams = [];
            foreach ($user->getTeams() as $team) {
                $teams[] = (int) $team->getId();
            }

            $abbr = (string) $user->getAbbr();
            $data[] = ['user' => [
                'id' => (int) $user->getId(),
                'username' => (string) $user->getUsername(),
                'type' => $user->getType()->value,
                'abbr' => $abbr,
                'abbr_duplicate' => ($abbrCounts[$abbr] ?? 0) > 1,
                'locale' => $user->getLocale(),
                'teams' => $teams,
                'active' => $user->getActive(),
                'last_activity' => $lastActivity[(int) $user->getId()] ?? null,
            ]];
        }

        return $data;
    }

    public function findOneByAbbr(string $abbr): ?User
    {
        $result = $this->findOneBy(['abbr' => $abbr]);

        return $result instanceof User ? $result : null;
    }

    /**
     * @return array<int, array{user: array{id:int, username:string, type:string, abbr:string, locale:string}}>
     */
    public function getUserById(int $currentUserId): array
    {
        $user = $this->find($currentUserId);

        $data = [];

        if ($user instanceof User) {
            $data[] = ['user' => [
                'id' => (int) $user->getId(),
                'username' => (string) $user->getUsername(),
                'type' => $user->getType()->value,
                'abbr' => (string) $user->getAbbr(),
                'locale' => $user->getLocale(),
            ]];
        }

        return $data;
    }
}
