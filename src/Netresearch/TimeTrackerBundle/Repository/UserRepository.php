<?php

namespace Netresearch\TimeTrackerBundle\Repository;

use Doctrine\ORM\EntityRepository;

class UserRepository extends EntityRepository
{
    public function getUsers($currentUserId)
    {
        /* @var $users \Netresearch\TimeTrackerBundle\Entity\User[] */
        $users = $this->createQueryBuilder('u')
            ->orderBy('u.username', 'ASC')
            ->getQuery()
            ->getResult();

        $data = array();
        foreach ($users as $user) {
            if ($currentUserId == $user->getId()) {

                // Set current user on top
                array_unshift($data, array('user' => array(
                    'id'        => $user->getId(),
                    'username'  => $user->getUsername(),
                    'type'      => $user->getType(),
                    'abbr'      => $user->getAbbr(),
                    'locale'    => $user->getLocale(),
                )));

            } else {

                $data[] = array('user' => array(
                    'id'    => $user->getId(),
                    'username'  => $user->getUsername(),
                    'type' => $user->getType(),
                    'abbr' => $user->getAbbr(),
                    'locale'    => $user->getLocale(),
                ));

            }
        }

        return $data;
    }

    public function getAllUsers()
    {
        $connection = $this->getEntityManager()->getConnection();
        $stmt = $connection->query("
            SELECT DISTINCT u.id, u.username, u.type, u.abbr, u.locale, GROUP_CONCAT(tu.team_id) AS teams
            FROM users u LEFT JOIN teams_users tu ON u.id = tu.user_id
            GROUP BY u.id ORDER BY username ASC;");

        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data = array();
        if (count($result)) {
            foreach ($result as $line) {
                $data[] = array('user' => array(
                    'id'       => $line['id'],
                    'username' => $line['username'],
                    'type'     => $line['type'],
                    'abbr'     => $line['abbr'],
                    'locale'   => $line['locale'],
                    'teams'    => explode(',', $line['teams']),
                ));
            }
        }

        return $data;
    }

    public function getUserById($currentUserId)
    {
        $user = $this->find($currentUserId);

        if (empty($user)) {
            return array();
        }

        $data = array();
        $data[] = array('user' => array(
            'id'    => $user->getId(),
            'username'  => $user->getUsername(),
            'type' => $user->getType(),
            'abbr' => $user->getAbbr(),
            'locale'    => $user->getLocale(),
        ));

        return $data;
    }
}
