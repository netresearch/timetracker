<?php
declare(strict_types=1);

namespace App\Service\Validation;

use App\Entity\Project;
use App\Repository\ProjectRepository;

final readonly class ProjectValidator
{
    public function __construct(private ProjectRepository $projectRepository)
    {
    }

    public function isNameUniqueForCustomer(string $name, int $customerId, ?int $currentProjectId): bool
    {
        $existing = $this->projectRepository->findOneBy(['name' => $name, 'customer' => $customerId]);
        if (!$existing instanceof Project) {
            return true;
        }

        return $existing->getId() === $currentProjectId;
    }
}


