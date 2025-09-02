<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;

final class GetHolidaysAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getHolidays', name: '_getHolidays_attr', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var \App\Repository\HolidayRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(\App\Entity\Holiday::class);
        $holidays = $objectRepository->findByMonth((int) date('Y'), (int) date('m'));

        return new JsonResponse($holidays);
    }
}
