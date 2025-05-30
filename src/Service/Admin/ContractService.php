<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Contract;
use App\Entity\User;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use App\Repository\ContractRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Result;

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
        /** @var ContractRepository $contractRepository */
        $this->contractRepository = $doctrine->getRepository(Contract::class);
        /** @var UserRepository $userRepository */
        $this->userRepository = $doctrine->getRepository(User::class);
    }

    /**
     * @return Contract[]
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
        $start = null;
        if (!empty($data['start'])) {
            try {
                $start = new DateTime($data['start']);
            } catch (\Exception $e) {
                return ['error' => 'Invalid start date format'];
            }
        } else {
            return ['error' => 'Start date is required'];
        }

        $end = null;
        if (!empty($data['end'])) {
            try {
                $end = new DateTime($data['end']);
            } catch (\Exception $e) {
                return ['error' => 'Invalid end date format'];
            }
        }

        // Get user
        $userId = (int)($data['user_id'] ?? 0);
        if (!$userId) {
            return ['error' => 'User is required'];
        }

        $user = $this->userRepository->find($userId);
        if (!$user) {
            return ['error' => 'User not found'];
        }

        if ($id !== 0) {
            /** @var Contract $contract */
            $contract = $this->contractRepository->find($id);
            if (!$contract) {
                return ['error' => 'Contract not found'];
            }
        } else {
            $contract = new Contract();
            $contract->setUser($user);
        }

        // Check for overlapping contracts
        if ($id === 0 || $contract->getStart() != $start || $contract->getEnd() != $end) {
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
                    $start,
                    $end,
                    $existingContract->getStart(),
                    $existingContract->getEnd()
                )) {
                    return ['error' => 'Contract dates overlap with an existing contract for this user'];
                }
            }
        }

        // Update contract fields
        $contract->setStart($start);
        $contract->setEnd($end);
        $contract->setHours0((float)($data['hours_0'] ?? 0));
        $contract->setHours1((float)($data['hours_1'] ?? 0));
        $contract->setHours2((float)($data['hours_2'] ?? 0));
        $contract->setHours3((float)($data['hours_3'] ?? 0));
        $contract->setHours4((float)($data['hours_4'] ?? 0));
        $contract->setHours5((float)($data['hours_5'] ?? 0));
        $contract->setHours6((float)($data['hours_6'] ?? 0));

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
