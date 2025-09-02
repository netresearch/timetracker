<?php

declare(strict_types=1);

namespace App\Controller\Status;

use App\Controller\BaseController;
use Symfony\Component\HttpFoundation\Request;

final class PageAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/status/page', name: 'check_page', methods: ['GET'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $login = $this->isLoggedIn($request);

        return $this->render('status.html.twig', [
            'loginClass' => ($login ? 'status_active' : 'status_inactive'),
            'apptitle' => $this->params->get('app_title'),
        ]);
    }
}
