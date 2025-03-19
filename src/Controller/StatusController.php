<?php

namespace App\Controller;

use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Security;

class StatusController extends BaseController
{
    public function __construct(
        private Security $security
    ) { }

    private function getStatus(): array
    {
        return [
            'loginStatus' => $this->security->isGranted('IS_AUTHENTICATED_FULLY')
        ];
    }

    public function checkAction()
    {
        return new JsonResponse($this->getStatus());
    }

    public function pageAction(Request $request)
    {
        // use Auto-Cookie-Login from BaseClass
        $this->checkLogin($request);

        $userId = $request->getSession()->get('loginId');
        $status = $this->getStatus();
        return $this->render('status.html.twig', array(
            'loginClass'    => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
            'apptitle'      => $this->params->get('app_title'),
        ));
    }
}
