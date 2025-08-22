<?php

namespace App\Controller;

use App\Entity\User;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;

class StatusController extends BaseController
{
    private \Symfony\Bundle\SecurityBundle\Security $security;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setSecurity(\Symfony\Bundle\SecurityBundle\Security $security): void
    {
        $this->security = $security;
    }

    /**
     * @return bool[]
     *
     * @psalm-return array{loginStatus: bool}
     */
    private function getStatus(): array
    {
        return [
            'loginStatus' => $this->security->isGranted('IS_AUTHENTICATED')
        ];
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/status/check', name: 'check_status', methods: ['GET'])]
    public function check(): \App\Model\JsonResponse
    {
        return new JsonResponse($this->getStatus());
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/status/page', name: 'check_page', methods: ['GET'])]
    public function page(): \Symfony\Component\HttpFoundation\Response
    {
        $status = $this->getStatus();

        return $this->render('status.html.twig', [
            'loginClass' => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
            'apptitle'   => $this->params->get('app_title'),
        ]);
    }
}
