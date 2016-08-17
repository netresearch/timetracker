<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
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

    public function checkAction()
    {
        $userId = $this->get('request')->getSession()->get('loginId');

        $status = $this->getStatus($userId);
        return new Response(json_encode($status));
    }

    public function pageAction(Request $request)
    {
        // use Auto-Cookie-Login from BaseClass
        $this->checkLogin($request);

        $userId = $this->get('request')->getSession()->get('loginId');
        $status = $this->getStatus($userId);
        return $this->render('NetresearchTimeTrackerBundle:Default:status.html.twig', array(
            'loginClass'    => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
            'apptitle'      => $this->container->getParameter('app_title'),
        ));
    }
}
