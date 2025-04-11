<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Activity;
use App\Entity\Entry;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\ActivityRepository;
use App\Repository\EntryRepository;

/**
 * Service for activity management.
 */
class ActivityService
{
    private ManagerRegistry $doctrine;
    private ActivityRepository $activityRepository;
    private EntryRepository $entryRepository;

    /**
     * ActivityService constructor.
     */
    public function __construct(
        ManagerRegistry $doctrine
    ) {
        $this->doctrine = $doctrine;
        $this->activityRepository = $doctrine->getRepository(Activity::class);
        $this->entryRepository = $doctrine->getRepository(Entry::class);
    }

    /**
     * Create or update an activity.
     *
     * @param array $data Activity data
     * @return array Result status
     */
    public function saveActivity(array $data): array
    {
        $entityManager = $this->doctrine->getManager();
        $id = (int)($data['id'] ?? 0);

        if ($id !== 0) {
            $activity = $this->activityRepository->find($id);
            if (!$activity) {
                return ['error' => 'Activity not found'];
            }
        } else {
            $activity = new Activity();
        }

        // Validate name
        $name = $data['name'] ?? '';
        if (strlen($name) < 3) {
            return ['error' => 'Please provide a valid activity name with at least 3 letters.'];
        }

        // Check for duplicate names
        $existingActivity = $this->activityRepository->findOneBy(['name' => $name]);
        if ($existingActivity && $existingActivity->getId() !== $id) {
            return ['error' => 'An activity with this name already exists.'];
        }

        // Update activity fields
        $activity->setName($name);
        $activity->setActive((bool)($data['active'] ?? false));
        $activity->setBillable((bool)($data['billable'] ?? false));
        $activity->setGlobal((bool)($data['global'] ?? false));

        // Save to database
        $entityManager->persist($activity);
        $entityManager->flush();

        return ['success' => true];
    }

    /**
     * Delete an activity.
     *
     * @param int $activityId Activity ID
     * @return array Result status
     */
    public function deleteActivity(int $activityId): array
    {
        $activity = $this->activityRepository->find($activityId);

        if (!$activity) {
            return ['error' => 'Activity not found'];
        }

        // Check if any entries are using this activity
        $entries = $this->entryRepository->findBy(['activity' => $activity]);
        if (count($entries) > 0) {
            return ['error' => 'Cannot delete activity because it is used by one or more time entries.'];
        }

        $entityManager = $this->doctrine->getManager();
        $entityManager->remove($activity);
        $entityManager->flush();

        return ['success' => true];
    }
}
