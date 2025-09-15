<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\TicketSystem;
use App\Model\JsonResponse;
use App\Model\Response;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function count;

final class GetTicketSystemsAction extends BaseController
{
    /**
     * @throws Exception
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getTicketSystems', name: '_getTicketSystems_attr', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request, #[CurrentUser] ?\App\Entity\User $user = null): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        /** @var \App\Repository\TicketSystemRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);
        $ticketSystems = $objectRepository->getAllTicketSystems();

        // Filter sensitive data for non-PL users
        if ($user && !($user->hasRole('ROLE_ADMIN') || $user->getType()->value === 'PL')) {
            $c = count($ticketSystems);
            for ($i = 0; $i < $c; ++$i) {
                unset($ticketSystems[$i]['ticketSystem']['login'], $ticketSystems[$i]['ticketSystem']['password'], $ticketSystems[$i]['ticketSystem']['publicKey'], $ticketSystems[$i]['ticketSystem']['privateKey'], $ticketSystems[$i]['ticketSystem']['oauthConsumerSecret'], $ticketSystems[$i]['ticketSystem']['oauthConsumerKey']);
            }
        }

        return new JsonResponse($ticketSystems);
    }
}
