<?php declare(strict_types=1);

namespace App\Controller;

use App\Model\Response;
use Symfony\Component\Routing\Annotation\Route;

class StatusController extends BaseController
{
    #[Route(path: '/status/check', name: 'check_status')]
    public function checkAction()
    {
        return new Response(
            json_encode(
                ['loginStatus' => $this->isGranted('IS_AUTHENTICATED_REMEMBERED')],
                \JSON_THROW_ON_ERROR
            )
        );
    }

    #[Route(path: '/status/page', name: 'check_page')]
    public function pageAction()
    {
        return $this->render('status.html.twig', [
            'loginClass' => ($this->isGranted('IS_AUTHENTICATED_REMEMBERED') ? 'status_active' : 'status_inactive'),
        ]);
    }
}
