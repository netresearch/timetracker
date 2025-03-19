<?php

namespace App\Controller;

use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class StatusController extends BaseController
{
    private function getLoginStatus($userId) {
        // Check user session
        if (1 > (int) $userId) {
            return false;
        } else {
            return true;
        }
    }


    private function getStatus($userId)
    {
        // initialize status
        $loginStatus = $this->getLoginStatus($userId);

        return array('loginStatus' => $loginStatus);
    }

    public function checkAction(Request $request)
    {
        $userId = $request->getSession()->get('loginId');

        $status = $this->getStatus($userId);
        return new JsonResponse($status);
    }

    public function pageAction(Request $request)
    {
        // use Auto-Cookie-Login from BaseClass
        $this->checkLogin($request);

        $userId = $request->getSession()->get('loginId');
        $status = $this->getStatus($userId);
        return $this->render('status.html.twig', array(
            'loginClass'    => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
            'apptitle'      => $this->container->getParameter('app_title'),
        ));
    }
}
