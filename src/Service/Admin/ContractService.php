<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Contract;
use App\Entity\User;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\ContractRepository;
use App\Repository\UserRepository;

/**
 * Service for contract management.
 */
class ContractService
{
    private ManagerRegistry $doctrine;
    private ContractRepository $contractRepository;
    private UserRepository $userRepository;

    /**
     * ContractService constructor.
     */
    public function __construct(
        ManagerRegistry $doctrine
    ) {
        $this->doctrine = $doctrine;
        $this->contractRepository = $doctrine->getRepository(Contract::class);
        $this->userRepository = $doctrine->getRepository(User::class);
    }

    /**
     * Get all contracts.
     *
     * @return array List of contracts
     */
    public function getAllContracts(): array
    {
        return $this->contractRepository->findAll();
    }

    /**
     * Create or update a contract.
     *
     * @param array $data Contract data
     * @return array Result status
     */
    public function saveContract(array $data): array
    {
        $entityManager = $this->doctrine->getManager();
        $id = (int)($data['id'] ?? 0);

        // Prepare dates
        $startDate = null;
        if (!empty($data['startDate'])) {
            try {
                $startDate = new DateTime($data['startDate']);
            } catch (\Exception $e) {
                return ['error' => 'Invalid start date format'];
            }
        } else {
            return ['error' => 'Start date is required'];
        }

        $endDate = null;
        if (!empty($data['endDate'])) {
            try {
                $endDate = new DateTime($data['endDate']);
            } catch (\Exception $e) {
                return ['error' => 'Invalid end date format'];
            }
        }

        // Get user
        $userId = (int)($data['userId'] ?? 0);
        if (!$userId) {
            return ['error' => 'User is required'];
        }

        $user = $this->userRepository->find($userId);
        if (!$user) {
            return ['error' => 'User not found'];
        }

        if ($id !== 0) {
            $contract = $this->contractRepository->find($id);
            if (!$contract) {
                return ['error' => 'Contract not found'];
            }
        } else {
            $contract = new Contract();
            $contract->setUser($user);
        }

        // Check for overlapping contracts
        if ($id === 0 || $contract->getStartDate() != $startDate || $contract->getEndDate() != $endDate) {
            $existingContracts = $this->contractRepository->findBy(['user' => $user]);

            // Filter out the current contract
            if ($id !== 0) {
                $existingContracts = array_filter($existingContracts, function ($existing) use ($id) {
                    return $existing->getId() !== $id;
                });
            }

            // Check for overlaps
            foreach ($existingContracts as $existingContract) {
                // Check if start date overlaps with any existing contract
                if ($this->datesOverlap(
                    $startDate,
                    $endDate,
                    $existingContract->getStartDate(),
                    $existingContract->getEndDate()
                )) {
                    return ['error' => 'Contract dates overlap with an existing contract for this user'];
                }
            }
        }

        // Update contract fields
        $contract->setStartDate($startDate);
        $contract->setEndDate($endDate);
        $contract->setWeeklyHours((float)($data['weeklyHours'] ?? 0));
        $contract->setWeeklyDays((int)($data['weeklyDays'] ?? 0));
        $contract->setDailyStartTime($data['dailyStartTime'] ?? null);
        $contract->setDailyEndTime($data['dailyEndTime'] ?? null);
        $contract->setVacationDays((int)($data['vacationDays'] ?? 0));
        $contract->setNotes($data['notes'] ?? null);

        // Save to database
        $entityManager->persist($contract);
        $entityManager->flush();

        return ['success' => true];
    }

    /**
     * Delete a contract.
     *
     * @param int $contractId Contract ID
     * @return array Result status
     */
    public function deleteContract(int $contractId): array
    {
        $contract = $this->contractRepository->find($contractId);

        if (!$contract) {
            return ['error' => 'Contract not found'];
        }

        $entityManager = $this->doctrine->getManager();
        $entityManager->remove($contract);
        $entityManager->flush();

        return ['success' => true];
    }

    /**
     * Check if two date ranges overlap.
     *
     * @param DateTime $start1 Start date of first range
     * @param DateTime|null $end1 End date of first range (or null if open-ended)
     * @param DateTime $start2 Start date of second range
     * @param DateTime|null $end2 End date of second range (or null if open-ended)
     * @return bool True if the ranges overlap
     */
    private function datesOverlap(DateTime $start1, ?DateTime $end1, DateTime $start2, ?DateTime $end2): bool
    {
        // If either range is open-ended (no end date), handle specially
        if (!$end1 && !$end2) {
            // Both open-ended, so they will overlap if one starts before the other ends
            return true;
        } elseif (!$end1) {
            // First range is open-ended, so it overlaps if it starts before the second ends
            return $start1 <= $end2;
        } elseif (!$end2) {
            // Second range is open-ended, so it overlaps if it starts before the first ends
            return $start2 <= $end1;
        }

        // Both ranges have end dates, check standard overlap
        return $start1 <= $end2 && $start2 <= $end1;
    }
}
