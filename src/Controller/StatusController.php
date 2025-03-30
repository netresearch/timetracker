<?php

namespace App\Controller;

use App\Entity\User;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;

class StatusController extends BaseController
{
    public function __construct(
        private readonly Security $security
    ) {
    }

    private function getStatus(): array
    {
        return [
            'loginStatus' => $this->security->isGranted('IS_AUTHENTICATED_REMEMBERED')
        ];
    }

    public function checkAction()
    {
        return new JsonResponse($this->getStatus());
    }

    public function pageAction(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $status = $this->getStatus();

        return $this->render('status.html.twig', [
            'loginClass' => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
            'apptitle'   => $this->params->get('app_title'),
        ]);
    }
}
