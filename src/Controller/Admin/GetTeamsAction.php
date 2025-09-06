<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;

final class GetTeamsAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllTeams', name: '_getAllTeams_attr', methods: ['GET'])]
    public function __invoke(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        /** @var \App\Repository\TeamRepository $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(\App\Entity\Team::class);

        return new JsonResponse($objectRepository->getAllTeamsAsArray());
    }
}
