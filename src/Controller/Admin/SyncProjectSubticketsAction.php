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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function is_scalar;

final class SyncProjectSubticketsAction extends BaseController
{
    private SubticketSyncService $subticketSyncService;

    #[Required]
    public function setSubticketSyncService(SubticketSyncService $subticketSyncService): void
    {
        $this->subticketSyncService = $subticketSyncService;
    }

    #[Route(path: '/projects/{project}/syncsubtickets', name: 'syncProjectSubtickets_attr_invokable', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request): JsonResponse|Error|ModelResponse
    {
        // Map path parameter to query for DTO compatibility
        if ($request->attributes->has('project') && !$request->query->has('project')) {
            /** @var mixed $projectAttr */
            $projectAttr = $request->attributes->get('project');
            $request->query->set('project', is_scalar($projectAttr) ? (string) $projectAttr : '0');
        }

        $adminSyncDto = AdminSyncDto::fromRequest($request);
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
            return new Error($exception->getMessage(), (int) (0 !== $exception->getCode() ? $exception->getCode() : 500));
        }
    }
}
