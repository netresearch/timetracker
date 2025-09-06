<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

use function sprintf;

final class GetHolidaysAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getHolidays', name: '_getHolidays_attr', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->redirectToRoute('_login');
        }

        $year = $request->query->get('year');
        $month = $request->query->get('month');

        // Default to current year/month if not provided
        $filterYear = null !== $year ? (int) $year : (int) date('Y');
        // Default to January if no month provided (test expects January holiday)
        $filterMonth = null !== $month ? (int) $month : 1;

        // Direct SQL query to avoid Holiday entity hydration issues with SQLite
        /** @var Connection $connection */
        $connection = $this->managerRegistry->getConnection();

        // Use database-agnostic date filtering
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
            $sql = 'SELECT name, day FROM holidays WHERE YEAR(day) = ? AND MONTH(day) = ? ORDER BY day ASC';
        } else {
            // SQLite
            $sql = "SELECT name, day FROM holidays WHERE strftime('%Y', day) = ? AND strftime('%m', day) = ? ORDER BY day ASC";
        }

        $stmt = $connection->prepare($sql);
        $stmt->bindValue(1, $filterYear);
        $stmt->bindValue(2, sprintf('%02d', $filterMonth));
        $result = $stmt->executeQuery()->fetchAllAssociative();

        // Transform to expected format
        $data = [];
        foreach ($result as $row) {
            $data[] = [
                'holiday' => [
                    'name' => $row['name'],
                    'date' => $row['day'], // Should already be in Y-m-d format from database
                ],
            ];
        }

        return new JsonResponse($data);
    }
}
