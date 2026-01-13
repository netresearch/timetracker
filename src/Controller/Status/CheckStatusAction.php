<?php

declare(strict_types=1);

namespace App\Controller\Status;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class CheckStatusAction extends BaseController
{
    #[Route(path: '/status/check', name: 'check_status', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $login = $this->isGranted('IS_AUTHENTICATED_FULLY');

        return new JsonResponse(['loginStatus' => $login]);
    }
}
