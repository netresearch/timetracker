<?php
declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Controller\CrudController;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;

final class SaveEntryAction extends CrudController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/save', name: 'timetracking_save_attr', methods: ['POST'])]
    public function __invoke(Request $request): Response|JsonResponse|Error
    {
        return parent::save($request);
    }
}


