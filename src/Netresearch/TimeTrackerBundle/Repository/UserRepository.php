<?php

namespace Netresearch\TimeTrackerBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Netresearch\TimeTrackerBundle\Entity\User;

/**
 * Class UserRepository
 * @package Netresearch\TimeTrackerBundle\Repository
 */
class UserRepository extends EntityRepository
{
    /**
     * @param integer $currentUserId
     * @return array
     */
    public function getUsers($currentUserId)
    {
        /** @var User[] $users */
        $users = $this->findBy(
            [], ['username' => 'ASC']
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
    public function getAllUsers()
    {
        /** @var User[] $users */
        $users = $this->findBy(
            [], ['username' => 'ASC']
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
     * @return array
     */
    public function getUserById($currentUserId)
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
