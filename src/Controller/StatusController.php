<?php

namespace App\Controller;

use App\Model\Response;
use Symfony\Component\Routing\Annotation\Route;

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

    #[Route(path: '/status/check', name: 'check_status')]
    public function checkAction()
    {
        $userId = $this->session->get('loginId');

        $status = $this->getStatus($userId);
        return new Response(json_encode($status, JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/status/page', name: 'check_page')]
    public function pageAction()
    {
        // use Auto-Cookie-Login from BaseClass
        $this->checkLogin();

        $userId = $this->session->get('loginId');
        $status = $this->getStatus($userId);
        return $this->render('App:Default:status.html.twig', array(
            'loginClass'    => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
        ));
    }
}
