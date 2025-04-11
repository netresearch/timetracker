<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Team;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service for managing users.
 */
class UserService
{
    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * UserService constructor.
     */
    public function __construct(
        ManagerRegistry $doctrine,
        TranslatorInterface $translator
    ) {
        $this->doctrine = $doctrine;
        $this->translator = $translator;
    }

    /**
     * Get all users.
     *
     * @return array All users
     */
    public function getAllUsers(): array
    {
        /** @var \App\Repository\UserRepository $repository */
        $repository = $this->doctrine->getRepository(User::class);
        return $repository->getAllUsers();
    }

    /**
     * Save (create or update) a user.
     *
     * @param array $data User data
     * @return array Result data with user ID and other info
     * @throws \Exception If validation fails
     */
    public function saveUser(array $data): array
    {
        $userId = (int) ($data['id'] ?? 0);
        $name = $data['username'] ?? '';
        $abbr = $data['abbr'] ?? '';
        $type = $data['type'] ?? '';
        $locale = $data['locale'] ?? '';
        $teamIds = $data['teams'] ?? [];

        /** @var \App\Repository\UserRepository $userRepository */
        $userRepository = $this->doctrine->getRepository(User::class);

        if ($userId !== 0) {
            // Update existing user
            $user = $userRepository->find($userId);
            if (!$user) {
                throw new \Exception($this->translator->trans('No entry for id.'), 404);
            }
        } else {
            // Create new user
            $user = new User();
        }

        // Validate user name
        if (strlen((string) $name) < 3) {
            throw new \Exception(
                $this->translator->trans('Please provide a valid user name with at least 3 letters.'),
                406
            );
        }

        // Validate user abbreviation
        if (strlen((string) $abbr) != 3) {
            throw new \Exception(
                $this->translator->trans('Please provide a valid user name abbreviation with 3 letters.'),
                406
            );
        }

        // Check for duplicate username
        $sameNamedUser = $userRepository->findOneByUsername($name);
        if ($sameNamedUser && $user->getId() != $sameNamedUser->getId()) {
            throw new \Exception(
                $this->translator->trans('The user name provided already exists.'),
                406
            );
        }

        // Check for duplicate abbreviation
        $sameAbbrUser = $userRepository->findOneByAbbr($abbr);
        if ($sameAbbrUser && $user->getId() != $sameAbbrUser->getId()) {
            throw new \Exception(
                $this->translator->trans('The user name abreviation provided already exists.'),
                406
            );
        }

        // Update user properties
        $user->setUsername($name)
            ->setAbbr($abbr)
            ->setLocale($locale)
            ->setType($type);

        // Reset and add teams
        $user->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }

            /** @var Team|null $team */
            $team = $this->doctrine->getRepository(Team::class)->find((int) $teamId);
            if ($team) {
                $user->addTeam($team);
            } else {
                throw new \Exception(
                    sprintf($this->translator->trans('Could not find team with ID %s.'), (int) $teamId),
                    406
                );
            }
        }

        // Validate teams
        if (0 == $user->getTeams()->count()) {
            throw new \Exception(
                $this->translator->trans('Every user must belong to at least one team'),
                406
            );
        }

        // Save the user
        $objectManager = $this->doctrine->getManager();
        $objectManager->persist($user);
        $objectManager->flush();

        return [
            'id' => $user->getId(),
            'username' => $name,
            'abbr' => $abbr,
            'type' => $type
        ];
    }

    /**
     * Delete a user.
     *
     * @param int $userId User ID to delete
     * @return bool True if successful
     * @throws \Exception If deletion fails
     */
    public function deleteUser(int $userId): bool
    {
        try {
            /** @var User|null $user */
            $user = $this->doctrine->getRepository(User::class)
                ->find($userId);

            if (!$user) {
                throw new \Exception($this->translator->trans('User not found.'), 404);
            }

            $em = $this->doctrine->getManager();
            $em->remove($user);
            $em->flush();

            return true;
        } catch (\Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translator->trans('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translator->trans('Dataset could not be removed. %s'), $reason);
            throw new \Exception($msg, 422);
        }
    }
}
