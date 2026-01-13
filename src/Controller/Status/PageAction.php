<?php

declare(strict_types=1);

namespace App\Controller\Status;

use App\Controller\BaseController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final class PageAction extends BaseController
{
    /**
     * @throws LoaderError  When template loading fails
     * @throws RuntimeError When template rendering fails
     * @throws SyntaxError  When template syntax is invalid
     */
    #[Route(path: '/status/page', name: 'check_page', methods: ['GET'])]
    public function __invoke(): Response
    {
        $login = $this->isGranted('IS_AUTHENTICATED_FULLY');

        return $this->render('status.html.twig', [
            'loginClass' => ($login ? 'status_active' : 'status_inactive'),
            'apptitle' => $this->params->get('app_title'),
        ]);
    }
}
