<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\User as User;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;

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
		$request = $this->getRequest();
        $userId = $this->get('request')->getSession()->get('loginId');

        $status = $this->getStatus($userId);
        return new Response(json_encode($status));
    }

    public function pageAction()
    {
        // use Auto-Cookie-Login from BaseClass
        $this->checkLogin();

		$request = $this->getRequest();
        $userId = $this->get('request')->getSession()->get('loginId');
        $status = $this->getStatus($userId);
        return $this->render('NetresearchTimeTrackerBundle:Default:status.html.twig', array(
            'loginClass' => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
        ));
    }
}
