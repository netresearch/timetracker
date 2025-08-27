<?php
declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\SettingsController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SaveSettingsAction extends SettingsController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/settings/save', name: 'saveSettings', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        return parent::save($request);
    }
}


