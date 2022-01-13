<?php declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * Class UserRepository.
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', \get_class($user)));
        }

        $user->setPassword($newHashedPassword);
        $this->_em->persist($user);
        $this->_em->flush();
    }
    
    /**
     * @param int $currentUserId
     *
     * @return array
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
            if ($currentUserId === $user->getId()) {
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
     *
     * @return array
     */
    public function getUserById($currentUserId): array
    {
        $user = $this->find($currentUserId);

        $data = [];

        if (!empty($user)) {
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
