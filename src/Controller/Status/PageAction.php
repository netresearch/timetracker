<?php

declare(strict_types=1);

namespace App\Controller\Status;

use App\Controller\BaseController;
use Symfony\Component\HttpFoundation\Request;

final class PageAction extends BaseController
{
    /**
     * @throws \Twig\Error\LoaderError  When template loading fails
     * @throws \Twig\Error\RuntimeError When template rendering fails
     * @throws \Twig\Error\SyntaxError  When template syntax is invalid
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/status/page', name: 'check_page', methods: ['GET'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $login = $this->isGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('status.html.twig', [
            'loginClass' => ($login ? 'status_active' : 'status_inactive'),
            'apptitle' => $this->params->get('app_title'),
        ]);
    }
}
