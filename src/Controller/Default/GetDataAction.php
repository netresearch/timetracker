<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Repository\EntryRepository;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetDataAction extends BaseController
{
    /**
     * @throws InvalidArgumentException When query parameters are invalid
     * @throws BadRequestException      When query parameters are malformed
     */
    #[Route(path: '/getData', name: '_getData_attr', methods: ['GET', 'POST'])]
    #[Route(path: '/getData/days/{days}', name: '_getDataDays_attr', defaults: ['days' => 3], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        if (!$user instanceof User) {
            return new JsonResponse([]);
        }

        $user->getId();

        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);

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

            $entries = $objectRepository->findByDate(
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

        $entries = $objectRepository->getEntriesByUser($user, $days, $user->getShowFuture());

        // Convert Entry entities to arrays for JSON serialization
        // Entry has protected properties that don't serialize with json_encode
        // Wrap each entry in 'entry' key as expected by ExtJS reader (record: 'entry')
        $data = [];
        foreach ($entries as $entry) {
            $data[] = ['entry' => $entry->toArray()];
        }

        return new JsonResponse($data);
    }
}
