<?php
declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Controller\CrudController;
use App\Model\Response;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;

final class BulkEntryAction extends CrudController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/bulkentry', name: 'timetracking_bulkentry_attr', methods: ['POST'])]
    public function __invoke(Request $request): Response|Error
    {
        return parent::bulkentry($request);
    }
}


