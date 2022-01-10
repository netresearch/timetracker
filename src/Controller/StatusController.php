<?php declare(strict_types=1);

namespace App\Controller;

use App\Model\Response;
use Symfony\Component\Routing\Annotation\Route;

class StatusController extends BaseController
{
    private function getLoginStatus(int $userId)
    {
        // Check user session
        return ($userId > 0);
    }

    private function getStatus(int $userId)
    {
        // initialize status
        $loginStatus = $this->getLoginStatus($userId);

        return ['loginStatus' => $loginStatus];
    }

    #[Route(path: '/status/check', name: 'check_status')]
    public function checkAction()
    {
        $userId = $this->session->get('loginId');

        $status = $this->getStatus($userId);

        return new Response(json_encode($status, \JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/status/page', name: 'check_page')]
    public function pageAction()
    {
        // use Auto-Cookie-Login from BaseClass
        $this->checkLogin();

        $userId = $this->session->get('loginId');
        $status = $this->getStatus($userId);

        return $this->render('status.html.twig', [
            'loginClass' => ($status['loginStatus'] ? 'status_active' : 'status_inactive'),
        ]);
    }
}
