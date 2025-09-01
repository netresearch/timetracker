<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class UserRepository.
 */
/**
 * @extends ServiceEntityRepository<\App\Entity\User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, User::class);
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
            ['username' => 'ASC']
        );

        $data = [];

        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if ($currentUserId == $user->getId()) {
                // Set current user on top
                array_unshift($data, ['user' => [
                    'id' => (int) $user->getId(),
                    'username' => (string) $user->getUsername(),
                    'type' => (string) $user->getType(),
                    'abbr' => (string) $user->getAbbr(),
                    'locale' => $user->getLocale(),
                ]]);
            } else {
                $data[] = ['user' => [
                    'id' => (int) $user->getId(),
                    'username' => (string) $user->getUsername(),
                    'type' => (string) $user->getType(),
                    'abbr' => (string) $user->getAbbr(),
                    'locale' => $user->getLocale(),
                ]];
            }
        }

        return $data;
    }

    /**
     * @return array<int, array{user: array{id:int, username:string, type:string, abbr:string, locale:string, teams: array<int, int>}}>
     */
    public function getAllUsers(): array
    {
        /** @var User[] $users */
        $users = $this->findBy(
            [],
            ['username' => 'ASC']
        );

        $data = [];
        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $teams = [];
            foreach ($user->getTeams() as $team) {
                $teams[] = (int) $team->getId();
            }

            $data[] = ['user' => [
                'id' => (int) $user->getId(),
                'username' => (string) $user->getUsername(),
                'type' => (string) $user->getType(),
                'abbr' => (string) $user->getAbbr(),
                'locale' => $user->getLocale(),
                'teams' => $teams,
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
                'type' => (string) $user->getType(),
                'abbr' => (string) $user->getAbbr(),
                'locale' => $user->getLocale(),
            ]];
        }

        return $data;
    }
}
