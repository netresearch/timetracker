<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\TicketSystem;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\TicketSystemRepository;
use App\Repository\ProjectRepository;

/**
 * Service for ticket system management.
 */
class TicketSystemService
{
    private ManagerRegistry $doctrine;
    private TicketSystemRepository $ticketSystemRepository;
    private ProjectRepository $projectRepository;

    /**
     * TicketSystemService constructor.
     */
    public function __construct(
        ManagerRegistry $doctrine
    ) {
        $this->doctrine = $doctrine;
        $this->ticketSystemRepository = $doctrine->getRepository(TicketSystem::class);
        $this->projectRepository = $doctrine->getRepository(\App\Entity\Project::class);
    }

    /**
     * Get all ticket systems, optionally with sensitive data filtered out.
     *
     * @param bool $isPl Whether the user is a project lead (determines if sensitive data is returned)
     * @return array List of ticket systems
     */
    public function getAllTicketSystems(bool $isPl): array
    {
        $ticketSystems = $this->ticketSystemRepository->getAllTicketSystems();

        if (!$isPl) {
            // Filter out sensitive information for non-PL users
            $c = count($ticketSystems);
            for ($i = 0; $i < $c; $i++) {
                unset($ticketSystems[$i]['ticketSystem']['login']);
                unset($ticketSystems[$i]['ticketSystem']['password']);
                unset($ticketSystems[$i]['ticketSystem']['publicKey']);
                unset($ticketSystems[$i]['ticketSystem']['privateKey']);
                unset($ticketSystems[$i]['ticketSystem']['oauthConsumerSecret']);
                unset($ticketSystems[$i]['ticketSystem']['oauthConsumerKey']);
            }
        }

        return $ticketSystems;
    }

    /**
     * Create or update a ticket system.
     *
     * @param array $data Ticket system data
     * @return array Result status
     */
    public function saveTicketSystem(array $data): array
    {
        $entityManager = $this->doctrine->getManager();
        $id = (int)($data['id'] ?? 0);

        if ($id !== 0) {
            $ticketSystem = $this->ticketSystemRepository->find($id);
            if (!$ticketSystem) {
                return ['error' => 'Ticket system not found'];
            }
        } else {
            $ticketSystem = new TicketSystem();
        }

        // Validate name
        $name = $data['name'] ?? '';
        if (strlen($name) < 3) {
            return ['error' => 'Please provide a valid ticket system name with at least 3 letters.'];
        }

        // Check for duplicate names
        $existingSystem = $this->ticketSystemRepository->findOneBy(['name' => $name]);
        if ($existingSystem && $existingSystem->getId() !== $id) {
            return ['error' => 'A ticket system with this name already exists.'];
        }

        // Update ticket system fields
        $ticketSystem->setName($name);
        $ticketSystem->setType($data['type'] ?? '');
        $ticketSystem->setUrl($data['url'] ?? '');
        $ticketSystem->setLogin($data['login'] ?? '');
        $ticketSystem->setActive((bool)($data['active'] ?? false));

        // Set password only if provided
        if (!empty($data['password'])) {
            $ticketSystem->setPassword($data['password']);
        }

        // Set OAuth-related fields
        $ticketSystem->setOauthEnabled((bool)($data['oauthEnabled'] ?? false));

        if (!empty($data['privateKey'])) {
            $ticketSystem->setPrivateKey($data['privateKey']);
        }

        if (!empty($data['publicKey'])) {
            $ticketSystem->setPublicKey($data['publicKey']);
        }

        if (!empty($data['oauthConsumerKey'])) {
            $ticketSystem->setOauthConsumerKey($data['oauthConsumerKey']);
        }

        if (!empty($data['oauthConsumerSecret'])) {
            $ticketSystem->setOauthConsumerSecret($data['oauthConsumerSecret']);
        }

        $ticketSystem->setProjectMappingField($data['projectMappingField'] ?? null);

        // Save to database
        $entityManager->persist($ticketSystem);
        $entityManager->flush();

        return ['success' => true];
    }

    /**
     * Delete a ticket system.
     *
     * @param int $ticketSystemId Ticket system ID
     * @return array Result status
     */
    public function deleteTicketSystem(int $ticketSystemId): array
    {
        $ticketSystem = $this->ticketSystemRepository->find($ticketSystemId);

        if (!$ticketSystem) {
            return ['error' => 'Ticket system not found'];
        }

        // Check if any projects are using this ticket system
        $projects = $this->projectRepository->findBy(['ticketSystem' => $ticketSystem]);
        if (count($projects) > 0) {
            return ['error' => 'Cannot delete ticket system because it is used by one or more projects.'];
        }

        $entityManager = $this->doctrine->getManager();
        $entityManager->remove($ticketSystem);
        $entityManager->flush();

        return ['success' => true];
    }
}
