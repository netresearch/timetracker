<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Team;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service for managing teams.
 */
class TeamService
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
     * TeamService constructor.
     */
    public function __construct(
        ManagerRegistry $doctrine,
        TranslatorInterface $translator
    ) {
        $this->doctrine = $doctrine;
        $this->translator = $translator;
    }

    /**
     * Get all teams.
     *
     * @return array All teams
     */
    public function getAllTeams(): array
    {
        /** @var \App\Repository\TeamRepository $repository */
        $repository = $this->doctrine->getRepository(Team::class);
        return $repository->findAll();
    }

    /**
     * Save (create or update) a team.
     *
     * @param array $data Team data
     * @return array Result data with team ID and other info
     * @throws \Exception If validation fails
     */
    public function saveTeam(array $data): array
    {
        $teamId = (int) ($data['id'] ?? 0);
        $name = $data['name'] ?? '';
        $leadUserId = $data['lead_user_id'] ?? null;

        /** @var \App\Repository\TeamRepository $teamRepository */
        $teamRepository = $this->doctrine->getRepository(Team::class);

        if ($teamId !== 0) {
            // Update existing team
            $team = $teamRepository->find($teamId);
            if (!$team) {
                throw new \Exception($this->translator->trans('No entry for id.'), 404);
            }
        } else {
            // Create new team
            $team = new Team();
        }

        // Get team lead user
        $teamLead = null;
        if ($leadUserId) {
            /** @var \App\Repository\UserRepository $userRepository */
            $userRepository = $this->doctrine->getRepository(User::class);
            $teamLead = $userRepository->find($leadUserId);
        }

        // Validate team lead
        if (!$teamLead) {
            throw new \Exception(
                $this->translator->trans('Please provide a valid user as team leader.'),
                406
            );
        }

        // Check for duplicate team name
        $sameNamedTeam = $teamRepository->findOneByName($name);
        if ($sameNamedTeam && $team->getId() != $sameNamedTeam->getId()) {
            throw new \Exception(
                $this->translator->trans('The team name provided already exists.'),
                406
            );
        }

        // Update team properties
        $team
            ->setName($name)
            ->setLeadUser($teamLead);

        try {
            // Save the team
            $em = $this->doctrine->getManager();
            $em->persist($team);
            $em->flush();

            return [
                'id' => $team->getId(),
                'name' => $team->getName(),
                'lead_user_id' => $team->getLeadUser() ? $team->getLeadUser()->getId() : null
            ];
        } catch (\Exception $exception) {
            throw new \Exception(
                $this->translator->trans('Error on save') . ': ' . $exception->getMessage(),
                403
            );
        }
    }

    /**
     * Delete a team.
     *
     * @param int $teamId Team ID to delete
     * @return bool True if successful
     * @throws \Exception If deletion fails
     */
    public function deleteTeam(int $teamId): bool
    {
        try {
            /** @var Team|null $team */
            $team = $this->doctrine->getRepository(Team::class)
                ->find($teamId);

            if (!$team) {
                throw new \Exception($this->translator->trans('Team not found.'), 404);
            }

            $em = $this->doctrine->getManager();
            $em->remove($team);
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
