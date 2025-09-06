<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Entity\User;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class GetDataAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getData', name: '_getData_attr', methods: ['GET', 'POST'])]
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getData/days/{days}', name: '_getDataDays_attr', defaults: ['days' => 3], methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return new JsonResponse(['error' => 'not authenticated'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }

        $userId = $this->getUserId($request);
        $user = $this->managerRegistry->getRepository(User::class)->find($userId);

        if (!$user instanceof User) {
            return new JsonResponse([]);
        }

        /** @var \App\Repository\EntryRepository $entryRepository */
        $entryRepository = $this->managerRegistry->getRepository(Entry::class);

        // Check if this is a filtered request (with year/month/user/customer/project parameters)
        $year = $request->query->get('year');
        $month = $request->query->get('month');
        $userParam = $request->query->get('user');
        $customer = $request->query->get('customer');
        $project = $request->query->get('project');

        if (null !== $year) {
            // Filtered request - use findByDate and calculate totalWorkTime
            // If no user parameter provided, use 0 to search all users
            $filterUserId = null !== $userParam ? (int) $userParam : 0;
            $filterYear = (int) $year;
            $filterMonth = null !== $month ? (int) $month : null;
            $filterProject = null !== $project ? (int) $project : null;
            $filterCustomer = null !== $customer ? (int) $customer : null;

            $entries = $entryRepository->findByDate(
                $filterUserId,
                $filterYear,
                $filterMonth,
                $filterProject,
                $filterCustomer,
            );

            // Calculate total work time from filtered entries
            $totalWorkTime = 0;
            foreach ($entries as $entry) {
                $totalWorkTime += $entry->getDuration();
            }

            return new JsonResponse(['totalWorkTime' => $totalWorkTime]);
        }

        // Default behavior - return entries for recent days
        $days = $request->attributes->has('days') && is_numeric($request->attributes->get('days'))
            ? (int) $request->attributes->get('days')
            : 3;

        $data = $entryRepository->getEntriesByUser($user, $days, $user->getShowFuture());

        return new JsonResponse($data);
    }
}
