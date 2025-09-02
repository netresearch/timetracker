<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\AdminSyncDto;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Response\Error;
use App\Service\SubticketSyncService;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;

final class SyncProjectSubticketsAction extends BaseController
{
    private SubticketSyncService $subticketSyncService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setSubticketSyncService(SubticketSyncService $subticketSyncService): void
    {
        $this->subticketSyncService = $subticketSyncService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/projects/{project}/syncsubtickets', name: 'syncProjectSubtickets_attr_invokable', methods: ['GET'])]
    public function __invoke(Request $request, #[MapQueryString] AdminSyncDto $adminSyncDto): JsonResponse|Error|ModelResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        $projectId = $adminSyncDto->project;

        try {
            $subtickets = $this->subticketSyncService->syncProjectSubtickets($projectId);

            return new JsonResponse(
                [
                    'success' => true,
                    'subtickets' => $subtickets,
                ],
            );
        } catch (Exception $exception) {
            return new Error($exception->getMessage(), (int) ($exception->getCode() ?: 500));
        }
    }
}
