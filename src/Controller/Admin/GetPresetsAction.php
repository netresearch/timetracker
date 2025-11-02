<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetPresetsAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getAllPresets', name: '_getAllPresets_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request): Response|JsonResponse
    {

        /** @var \App\Repository\PresetRepository<\App\Entity\Preset> $objectRepository */
        $objectRepository = $this->doctrineRegistry->getRepository(\App\Entity\Preset::class);

        return new JsonResponse($objectRepository->getAllPresets());
    }
}
