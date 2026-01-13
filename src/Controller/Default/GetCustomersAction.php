<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Customer;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\CustomerRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

use function assert;

final class GetCustomersAction extends BaseController
{
    #[Route(path: '/getCustomers', name: '_getCustomers_attr', methods: ['GET'])]
    public function __invoke(#[CurrentUser] ?User $user = null): RedirectResponse|Response|JsonResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();
        $objectRepository = $this->managerRegistry->getRepository(Customer::class);
        assert($objectRepository instanceof CustomerRepository);
        $data = $objectRepository->getCustomersByUser($userId);

        return new JsonResponse($data);
    }
}
