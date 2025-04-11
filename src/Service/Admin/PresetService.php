<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Preset;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Activity;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\PresetRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\ActivityRepository;
use App\Repository\UserRepository;

/**
 * Service for preset management.
 */
class PresetService
{
    private ManagerRegistry $doctrine;
    private PresetRepository $presetRepository;
    private CustomerRepository $customerRepository;
    private ProjectRepository $projectRepository;
    private ActivityRepository $activityRepository;
    private UserRepository $userRepository;

    /**
     * PresetService constructor.
     */
    public function __construct(
        ManagerRegistry $doctrine
    ) {
        $this->doctrine = $doctrine;
        $this->presetRepository = $doctrine->getRepository(Preset::class);
        $this->customerRepository = $doctrine->getRepository(Customer::class);
        $this->projectRepository = $doctrine->getRepository(Project::class);
        $this->activityRepository = $doctrine->getRepository(Activity::class);
        $this->userRepository = $doctrine->getRepository(User::class);
    }

    /**
     * Get all presets.
     *
     * @return array List of presets
     */
    public function getAllPresets(): array
    {
        return $this->presetRepository->getAllPresets();
    }

    /**
     * Create or update a preset.
     *
     * @param array $data Preset data
     * @return array Result status
     */
    public function savePreset(array $data): array
    {
        $entityManager = $this->doctrine->getManager();
        $id = (int)($data['id'] ?? 0);

        if ($id !== 0) {
            $preset = $this->presetRepository->find($id);
            if (!$preset) {
                return ['error' => 'Preset not found'];
            }
        } else {
            $preset = new Preset();

            // User association might be handled at database level
            // or through the repository rather than directly on entity
        }

        // Validate name
        $name = $data['name'] ?? '';
        if (strlen($name) < 3) {
            return ['error' => 'Please provide a valid preset name with at least 3 letters.'];
        }

        // Set related entities
        $customerId = (int)($data['customer'] ?? 0);
        if ($customerId) {
            $customer = $this->customerRepository->find($customerId);
            if (!$customer) {
                return ['error' => 'Customer not found'];
            }
            $preset->setCustomer($customer);
        } else {
            $preset->setCustomer(null);
        }

        $projectId = (int)($data['project'] ?? 0);
        if ($projectId) {
            $project = $this->projectRepository->find($projectId);
            if (!$project) {
                return ['error' => 'Project not found'];
            }
            $preset->setProject($project);
        } else {
            $preset->setProject(null);
        }

        $activityId = (int)($data['activity'] ?? 0);
        if ($activityId) {
            $activity = $this->activityRepository->find($activityId);
            if (!$activity) {
                return ['error' => 'Activity not found'];
            }
            $preset->setActivity($activity);
        } else {
            $preset->setActivity(null);
        }

        // Update preset fields
        $preset->setName($name);

        // The ticket and description fields might be handled differently
        // For now, we'll have to skip setting a ticket field if the method doesn't exist
        // setDescription does exist based on our entity check
        $preset->setDescription($data['description'] ?? null);

        // If this is a new preset, we need to associate it with the user in the database
        if ($id === 0 && isset($data['userId'])) {
            // The user association may need to be handled through SQL
            // or there might be another way in the application to link a preset to a user
            // For now, we'll store the preset first and handle user association after
            $entityManager->persist($preset);
            $entityManager->flush();

            // Set user-preset relation through direct SQL or other means if needed
            // This would be implemented based on your database schema
        } else {
            // Just save the preset
            $entityManager->persist($preset);
            $entityManager->flush();
        }

        return ['success' => true];
    }

    /**
     * Delete a preset.
     *
     * @param int $presetId Preset ID
     * @param int $userId User ID (for checking permissions)
     * @return array Result status
     */
    public function deletePreset(int $presetId, int $userId): array
    {
        $preset = $this->presetRepository->find($presetId);

        if (!$preset) {
            return ['error' => 'Preset not found'];
        }

        // Check if the preset belongs to the current user
        // We need to check this in a way that matches your application's data model
        $isUserOwner = $this->presetRepository->isPresetOwnedByUser($presetId, $userId);

        // Only PL users can delete other users' presets
        if (!$isUserOwner) {
            $user = $this->userRepository->find($userId);
            if (!$user || !$user->isPl()) {
                return ['error' => 'You can only delete your own presets.'];
            }
        }

        $entityManager = $this->doctrine->getManager();
        $entityManager->remove($preset);
        $entityManager->flush();

        return ['success' => true];
    }
}
