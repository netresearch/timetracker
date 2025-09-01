<?php
declare(strict_types=1);

namespace App\Service\Validation;

use App\Entity\Customer;
use App\Repository\CustomerRepository;

final readonly class CustomerValidator
{
    public function __construct(private CustomerRepository $customerRepository)
    {
    }

    public function isNameUnique(string $name, ?int $currentCustomerId): bool
    {
        $existing = $this->customerRepository->findOneByName($name);
        if (!$existing instanceof Customer) {
            return true;
        }

        return $existing->getId() === $currentCustomerId;
    }
}


