<?php

namespace App\Repository;

use Doctrine\ORM\EntityRepository;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class UserRepository
 * @package App\Repository
 */
class UserRepository extends ServiceEntityRepository
{
    /**
     * UserRepository constructor.
     */
    public function __construct(ManagerRegistry $managerRegistry)
    {
        parent::__construct($managerRegistry, User::class);
    }

    /**
     * Find a user by username
     */
    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    /**
     * @param integer $currentUserId
     */
    public function getUsers($currentUserId): array
    {
        /** @var User[] $users */
        $users = $this->findBy(
            [],
            ['username' => 'ASC']
        );

        $data = [];

        foreach ($users as $user) {
            if ($currentUserId == $user->getId()) {

                // Set current user on top
                array_unshift($data, ['user' => [
                    'id'       => $user->getId(),
                    'username' => $user->getUsername(),
                    'type'     => $user->getType(),
                    'abbr'     => $user->getAbbr(),
                    'locale'   => $user->getLocale(),
                ]]);
            } else {
                $data[] = ['user' => [
                    'id'       => $user->getId(),
                    'username' => $user->getUsername(),
                    'type'     => $user->getType(),
                    'abbr'     => $user->getAbbr(),
                    'locale'   => $user->getLocale(),
                ]];
            }
        }

        return $data;
    }

    /**
     * @return array[]
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
            $teams = [];
            foreach ($user->getTeams() as $team) {
                $teams[] = $team->getId();
            }

            $data[] = ['user' => [
                'id'       => $user->getId(),
                'username' => $user->getUsername(),
                'type'     => $user->getType(),
                'abbr'     => $user->getAbbr(),
                'locale'   => $user->getLocale(),
                'teams'    => $teams,
            ]];
        }

        return $data;
    }

    /**
     * @param $currentUserId
     */
    public function getUserById($currentUserId): array
    {
        $user = $this->find($currentUserId);

        $data = [];

        if (! empty($user)) {
            $data[] = ['user' => [
                'id'       => $user->getId(),
                'username' => $user->getUsername(),
                'type'     => $user->getType(),
                'abbr'     => $user->getAbbr(),
                'locale'   => $user->getLocale(),
            ]];
        }

        return $data;
    }
}
