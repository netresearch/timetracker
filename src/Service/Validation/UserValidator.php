<?php
declare(strict_types=1);

namespace App\Service\Validation;

use App\Entity\User;
use App\Repository\UserRepository;

final class UserValidator
{
    public function __construct(private readonly UserRepository $userRepository)
    {
    }

    public function isUsernameUnique(string $username, ?int $currentUserId): bool
    {
        $existing = $this->userRepository->findOneByUsername($username);
        if (!$existing instanceof User) {
            return true;
        }
        return $existing->getId() === $currentUserId;
    }

    public function isAbbrUnique(string $abbr, ?int $currentUserId): bool
    {
        $existing = $this->userRepository->findOneByAbbr($abbr);
        if (!$existing instanceof User) {
            return true;
        }
        return $existing->getId() === $currentUserId;
    }
}


