<?php
declare(strict_types=1);

namespace App\Controller\Status;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CheckStatusAction extends BaseController
{
    private \Symfony\Bundle\SecurityBundle\Security $security;
    private RequestStack $requestStack;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setSecurity(\Symfony\Bundle\SecurityBundle\Security $security): void
    {
        $this->security = $security;
    }

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setRequestStack(RequestStack $requestStack): void
    {
        $this->requestStack = $requestStack;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/status/check', name: 'check_status', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $login = $this->isLoggedIn($request);

        return new JsonResponse(['loginStatus' => $login]);
    }
}


