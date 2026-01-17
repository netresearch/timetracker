<?php

declare(strict_types=1);

namespace App\Controller\Status;

use App\Controller\BaseController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StatusPageAction extends BaseController
{
    #[Route(path: '/status/page', name: 'status_page', methods: ['GET'])]
    public function __invoke(): Response
    {
        $isLoggedIn = $this->isGranted('IS_AUTHENTICATED_FULLY');
        $statusClass = $isLoggedIn ? 'status_active' : 'status_inactive';

        $html = <<<HTML
            <!DOCTYPE HTML>
            <html>
            <head>
                <title>Login-Status</title>
                <style>
                    .status_active { color: green; }
                    .status_inactive { color: red; }
                </style>
            </head>
            <body>
                <h1>Login-Status</h1>
                <p class="{$statusClass}">{$statusClass}</p>
            </body>
            </html>
            HTML;

        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }
}
