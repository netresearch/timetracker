<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\Customer;
use App\Entity\Team;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service for managing customers.
 */
class CustomerService
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
     * CustomerService constructor.
     */
    public function __construct(
        ManagerRegistry $doctrine,
        TranslatorInterface $translator
    ) {
        $this->doctrine = $doctrine;
        $this->translator = $translator;
    }

    /**
     * Get all customers.
     *
     * @return array All customers
     */
    public function getAllCustomers(): array
    {
        /** @var \App\Repository\CustomerRepository $repository */
        $repository = $this->doctrine->getRepository(Customer::class);
        return $repository->getAllCustomers();
    }

    /**
     * Save (create or update) a customer.
     *
     * @param array $data Customer data
     * @return array Result data with customer ID and other info
     * @throws \Exception If validation fails
     */
    public function saveCustomer(array $data): array
    {
        $customerId = (int) ($data['id'] ?? 0);
        $name = $data['name'] ?? '';
        $active = $data['active'] ?? 0;
        $global = $data['global'] ?? 0;
        $teamIds = $data['teams'] ?? [];

        /** @var \App\Repository\CustomerRepository $customerRepository */
        $customerRepository = $this->doctrine->getRepository(Customer::class);

        if ($customerId !== 0) {
            // Update existing customer
            $customer = $customerRepository->find($customerId);
            if (!$customer) {
                throw new \Exception($this->translator->trans('No entry for id.'), 404);
            }
        } else {
            // Create new customer
            $customer = new Customer();
        }

        // Validate customer name
        if (strlen((string) $name) < 3) {
            throw new \Exception(
                $this->translator->trans('Please provide a valid customer name with at least 3 letters.'),
                406
            );
        }

        // Check for duplicate customer name
        $sameNamedCustomer = $customerRepository->findOneByName($name);
        if ($sameNamedCustomer && $customer->getId() != $sameNamedCustomer->getId()) {
            throw new \Exception(
                $this->translator->trans('The customer name provided already exists.'),
                406
            );
        }

        // Update customer properties
        $customer->setName($name)
            ->setActive($active)
            ->setGlobal($global);

        // Reset and add teams
        $customer->resetTeams();

        foreach ($teamIds as $teamId) {
            if (!$teamId) {
                continue;
            }

            /** @var Team|null $team */
            $team = $this->doctrine->getRepository(Team::class)->find((int) $teamId);
            if ($team) {
                $customer->addTeam($team);
            } else {
                throw new \Exception(
                    sprintf($this->translator->trans('Could not find team with ID %s.'), (int) $teamId),
                    406
                );
            }
        }

        // Validate teams for non-global customers
        if (0 == $customer->getTeams()->count() && false == $global) {
            throw new \Exception(
                $this->translator->trans('Every customer must belong to at least one team if it is not global.'),
                406
            );
        }

        // Save the customer
        $objectManager = $this->doctrine->getManager();
        $objectManager->persist($customer);
        $objectManager->flush();

        return [
            'id' => $customer->getId(),
            'name' => $name,
            'active' => $active,
            'global' => $global,
            'teams' => $teamIds
        ];
    }

    /**
     * Delete a customer.
     *
     * @param int $customerId Customer ID to delete
     * @return bool True if successful
     * @throws \Exception If deletion fails
     */
    public function deleteCustomer(int $customerId): bool
    {
        try {
            /** @var Customer|null $customer */
            $customer = $this->doctrine->getRepository(Customer::class)
                ->find($customerId);

            if (!$customer) {
                throw new \Exception($this->translator->trans('Customer not found.'), 404);
            }

            $em = $this->doctrine->getManager();
            $em->remove($customer);
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
