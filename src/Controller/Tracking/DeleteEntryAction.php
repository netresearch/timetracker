<?php
declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Controller\CrudController;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;

final class DeleteEntryAction extends CrudController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/delete', name: 'timetracking_delete_attr', methods: ['POST'])]
    public function __invoke(Request $request): Response|JsonResponse|Error
    {
        return parent::delete($request);
    }
}


