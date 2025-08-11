<?php

namespace App\Controller;

use App\Entity\User;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Routing\Annotation\Route;

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

    /**
     * @Route("/status/check", name="check_status", methods={"GET"})
     */
    public function checkAction()
    {
        return new JsonResponse($this->getStatus());
    }

    /**
     * @Route("/status/page", name="check_page", methods={"GET"})
     */
    public function pageAction(): \Symfony\Component\HttpFoundation\Response
    {
        $status = $this->getStatus();

        return $this->render('status.html.twig', [
            'loginClass' => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
            'apptitle'   => $this->params->get('app_title'),
        ]);
    }
}
