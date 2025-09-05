<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\QueryBuilder;
use App\Entity\Entry;
use App\Service\Util\TimeCalculationService;
use App\Dto\DatabaseResultDto;
use App\Enum\Period;
use App\Service\ClockInterface;

/**
 * @extends ServiceEntityRepository<Entry>
 */
class EntryRepository extends ServiceEntityRepository
{
    private TimeCalculationService $timeCalculationService;
    private ClockInterface $clock;

    public function __construct(
        ManagerRegistry $registry, 
        TimeCalculationService $timeCalculationService,
        ClockInterface $clock
    ) {
        parent::__construct($registry, Entry::class);
        $this->timeCalculationService = $timeCalculationService;
        $this->clock = $clock;
    }

    /**
     * Get all entries for specific day.
     *
     * @return Entry[]
     */
    public function getEntriesForDay($user, string $day): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.day = :day')
            ->setParameter('user', $user)
            ->setParameter('day', $day)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get entries for a specific month.
     *
     * @return Entry[]
     */
    public function getEntriesForMonth($user, string $startDate, string $endDate): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.day >= :startDate')
            ->andWhere('e.day <= :endDate')
            ->setParameter('user', $user)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('e.day', 'ASC')
            ->addOrderBy('e.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count entries for a specific user.
     */
    public function getCountByUser($user): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Delete entries for a specific user.
     */
    public function deleteByUserId($user): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete entries for a specific activity.
     */
    public function deleteByActivityId($activity): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.activity = :activity')
            ->setParameter('activity', $activity)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete entries for a specific project.
     */
    public function deleteByProjectId($project): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->execute();
    }

    /**
     * Delete entries for a specific customer.
     */
    public function deleteByCustomerId($customer): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->execute();
    }

    /**
     * Get entries with related entities for efficient data loading.
     */
    public function findEntriesWithRelations(array $conditions = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')
            ->leftJoin('e.customer', 'c')
            ->leftJoin('e.project', 'p')
            ->leftJoin('e.activity', 'a')
            ;

        foreach ($conditions as $field => $value) {
            $qb->andWhere("e.{$field} = :{$field}")
                ->setParameter($field, $value);
        }

        return $qb;
    }

    /**
     * Find entries by multiple IDs efficiently.
     *
     * @param int[] $ids
     * @return Entry[]
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total duration for entries matching conditions.
     */
    public function getTotalDuration(array $conditions = []): float
    {
        $qb = $this->createQueryBuilder('e')
            ->select('SUM(e.duration)');

        foreach ($conditions as $field => $value) {
            $qb->andWhere("e.{$field} = :{$field}")
                ->setParameter($field, $value);
        }

        return (float) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Check if entry exists with given conditions.
     */
    public function existsWithConditions(array $conditions): bool
    {
        $qb = $this->createQueryBuilder('e')
            ->select('1');

        foreach ($conditions as $field => $value) {
            $qb->andWhere("e.{$field} = :{$field}")
                ->setParameter($field, $value);
        }

        return null !== $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Get database-agnostic date/time formatting functions
     *
     * @return array{dateFormat: string, timeFormat: string}
     */
    private function getDateTimeFormats(): array
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();
        
        // Check for MySQL/MariaDB platforms (MariaDB is detected as MySQL platform)
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform || 
            $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
            return [
                'dateFormat' => "DATE_FORMAT(e.day, '%d/%m/%Y')",
                'timeFormat' => "DATE_FORMAT(e.{field},'%H:%i')",
            ];
        }
        
        // SQLite and other databases
        return [
            'dateFormat' => "strftime('%d/%m/%Y', e.day)",
            'timeFormat' => "strftime('%H:%M', e.{field})",
        ];
    }

    /**
     * Get database-agnostic SQL functions for date/year/month operations
     *
     * @return array{yearFunction: string, monthFunction: string, weekFunction: string, concatFunction: string, ifFunction: string}
     */
    private function getDatabaseSpecificFunctions(): array
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();
        
        // Check for MySQL/MariaDB platforms
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ||
            $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
            return [
                'yearFunction' => 'YEAR({field})',
                'monthFunction' => 'MONTH({field})',
                'weekFunction' => 'WEEK({field}, 1)',
                'concatFunction' => 'CONCAT({fields})',
                'ifFunction' => 'IF({condition}, {then}, {else})',
            ];
        }
        
        // SQLite and other databases  
        return [
            'yearFunction' => "strftime('%Y', {field})",
            'monthFunction' => "strftime('%m', {field})",
            'weekFunction' => "strftime('%W', {field})",
            'concatFunction' => '({fields})',
            'ifFunction' => 'CASE WHEN {condition} THEN {then} ELSE {else} END',
        ];
    }

    /**
     * Generate database-agnostic YEAR expression
     */
    private function generateYearExpression(string $field): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();
        
        // Check for MySQL/MariaDB platforms
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ||
            $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
            return "YEAR($field)";
        }
        
        return "strftime('%Y', $field)";
    }

    /**
     * Generate database-agnostic MONTH expression  
     */
    private function generateMonthExpression(string $field): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();
        
        // Check for MySQL/MariaDB platforms
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ||
            $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
            return "MONTH($field)";
        }
        
        return "strftime('%m', $field)";
    }

    /**
     * Generate database-agnostic WEEK expression
     */
    private function generateWeekExpression(string $field): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();
        
        // Check for MySQL/MariaDB platforms
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform ||
            $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
            return "WEEK($field, 1)";
        }
        
        return "strftime('%W', $field)";
    }

    /**
     * Execute raw SQL with optimized direct connection approach
     */
    public function getRawData(string $startDate, string $endDate, ?int $userId = null): array
    {
        $connection = $this->getEntityManager()->getConnection();
        
        // Get database-agnostic date/time formatting functions
        $formats = $this->getDateTimeFormats();
        $startTimeFormat = str_replace('{field}', 'start', $formats['timeFormat']);
        $endTimeFormat = str_replace('{field}', 'end', $formats['timeFormat']);

        $sql = [];
        $sql['select'] = "SELECT e.id,
        	{$formats['dateFormat']} AS `date`,
        	{$startTimeFormat} AS `start`,
         	{$endTimeFormat} AS `end`,
        	e.user_id AS user,
        	e.customer_id AS customer,
        	e.project_id AS project,
        	e.activity_id AS activity,
        	e.description,
            e.ticket,
            e.class,
            e.duration,
            e.internal_jira_ticket_original_key as extTicket,
            u.abbr AS userAbbr,
            c.name AS customerName,
            p.name AS projectName,
            a.name AS activityName";

        $sql['from'] = 'FROM entries e';
        $sql['joins'] = 'LEFT JOIN users u ON e.user_id = u.id
                        LEFT JOIN customers c ON e.customer_id = c.id  
                        LEFT JOIN projects p ON e.project_id = p.id
                        LEFT JOIN activities a ON e.activity_id = a.id';

        $sql['where'] = 'WHERE e.day >= ? AND e.day <= ?';
        $sql['order'] = 'ORDER BY e.day ASC, e.start ASC';

        $params = [1 => $startDate, 2 => $endDate];

        if (null !== $userId) {
            $sql['where'] .= ' AND e.user_id = ?';
            $params[3] = $userId;
        }

        // Modified: Use prepare and executeQuery with parameters
        $statement = $connection->prepare(implode(' ', $sql));
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $result = $statement->executeQuery()->fetchAllAssociative(); // Use fetchAllAssociative for DBAL 3+

        $data = [];
        foreach ($result as $line) {
            // Type-safe transformation using DTO
            $transformedLine = DatabaseResultDto::transformEntryRow($line);
            // Ensure duration is numeric (int or float) for formatDuration
            $duration = is_numeric($transformedLine['duration']) ? (float) $transformedLine['duration'] : 0;
            $transformedLine['duration'] = $this->timeCalculationService->formatDuration($duration);
            $data[] = $transformedLine;
        }

        return $data;
    }

    /**
     * Get entries with pagination and filtering
     */
    public function getFilteredEntries(
        array $filters = [],
        int $offset = 0,
        int $limit = 50,
        string $orderBy = 'day',
        string $orderDirection = 'DESC'
    ): array {
        $qb = $this->findEntriesWithRelations();

        // Apply filters
        foreach ($filters as $field => $value) {
            if (null !== $value && '' !== $value) {
                if (in_array($field, ['startDate', 'endDate'], true)) {
                    $operator = 'startDate' === $field ? '>=' : '<=';
                    $fieldName = 'day';
                    $qb->andWhere("e.{$fieldName} {$operator} :{$field}")
                        ->setParameter($field, $value);
                } else {
                    $qb->andWhere("e.{$field} = :{$field}")
                        ->setParameter($field, $value);
                }
            }
        }

        // Apply ordering
        $validOrderFields = ['id', 'day', 'start', 'end', 'duration'];
        if (in_array($orderBy, $validOrderFields, true)) {
            $orderDirection = strtoupper($orderDirection);
            if (!in_array($orderDirection, ['ASC', 'DESC'], true)) {
                $orderDirection = 'DESC';
            }
            $qb->orderBy("e.{$orderBy}", $orderDirection);
        }

        // Apply pagination
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }
        if ($offset > 0) {
            $qb->setFirstResult($offset);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get summary data for reporting
     */
    public function getSummaryData(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select([
                'COUNT(e.id) as entryCount',
                'SUM(e.duration) as totalDuration',
                'AVG(e.duration) as avgDuration',
                'MIN(e.day) as minDate',
                'MAX(e.day) as maxDate'
            ]);

        foreach ($filters as $field => $value) {
            if (null !== $value && '' !== $value) {
                if (in_array($field, ['startDate', 'endDate'], true)) {
                    $operator = 'startDate' === $field ? '>=' : '<=';
                    $fieldName = 'day';
                    $qb->andWhere("e.{$fieldName} {$operator} :{$field}")
                        ->setParameter($field, $value);
                } else {
                    $qb->andWhere("e.{$field} = :{$field}")
                        ->setParameter($field, $value);
                }
            }
        }

        $result = $qb->getQuery()->getSingleResult();

        // Ensure numeric values
        return [
            'entryCount' => (int) $result['entryCount'],
            'totalDuration' => (float) ($result['totalDuration'] ?? 0),
            'avgDuration' => (float) ($result['avgDuration'] ?? 0),
            'minDate' => $result['minDate'],
            'maxDate' => $result['maxDate']
        ];
    }

    /**
     * Get time summary grouped by criteria (for charts/reports)
     */
    public function getTimeSummaryByPeriod(
        string $period,
        array $filters = [],
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $connection = $this->getEntityManager()->getConnection();
        $functions = $this->getDatabaseSpecificFunctions();

        // Period function mapping
        $periodFunctions = [
            'year' => $functions['yearFunction'],
            'month' => $functions['monthFunction'],
            'week' => $functions['weekFunction']
        ];

        if (!isset($periodFunctions[$period])) {
            throw new \InvalidArgumentException("Invalid period: {$period}");
        }

        $groupByFunction = str_replace('{field}', 'e.day', $periodFunctions[$period]);

        $sql = "SELECT {$groupByFunction} as period_value,
                       SUM(e.duration) as total_duration,
                       COUNT(e.id) as entry_count
                FROM entries e
                WHERE 1 = 1";

        $params = [];
        $paramCounter = 1;

        // Add date range filters
        if ($startDate) {
            $sql .= " AND e.day >= ?";
            $params[$paramCounter++] = $startDate;
        }
        if ($endDate) {
            $sql .= " AND e.day <= ?";
            $params[$paramCounter++] = $endDate;
        }

        // Add additional filters
        foreach ($filters as $field => $value) {
            if (null !== $value && '' !== $value) {
                $sql .= " AND e.{$field} = ?";
                $params[$paramCounter++] = $value;
            }
        }

        $sql .= " GROUP BY {$groupByFunction} ORDER BY period_value";

        $stmt = $connection->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        return $stmt->executeQuery()->fetchAllAssociative();
    }

    /**
     * Bulk update entries
     */
    public function bulkUpdate(array $entryIds, array $updateData): int
    {
        if (empty($entryIds) || empty($updateData)) {
            return 0;
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->update(Entry::class, 'e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $entryIds);

        foreach ($updateData as $field => $value) {
            $qb->set("e.{$field}", ":{$field}")
                ->setParameter($field, $value);
        }

        return $qb->getQuery()->execute();
    }

    /**
     * Query entries by filter array for pagination
     * 
     * @param array<string, mixed> $arFilter
     * @return \Doctrine\ORM\Query<int, Entry>
     */
    public function queryByFilterArray(array $arFilter): \Doctrine\ORM\Query
    {
        $queryBuilder = $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')
            ->leftJoin('e.customer', 'c')
            ->leftJoin('e.project', 'p')
            ->leftJoin('e.activity', 'a')
            ;

        // Apply filters
        if (isset($arFilter['customer']) && null !== $arFilter['customer']) {
            if (is_object($arFilter['customer'])) {
                $queryBuilder->andWhere('e.customer = :customer')
                    ->setParameter('customer', $arFilter['customer']);
            } else {
                $queryBuilder->andWhere('IDENTITY(e.customer) = :customer')
                    ->setParameter('customer', $arFilter['customer']);
            }
        }

        if (isset($arFilter['project']) && null !== $arFilter['project']) {
            if (is_object($arFilter['project'])) {
                $queryBuilder->andWhere('e.project = :project')
                    ->setParameter('project', $arFilter['project']);
            } else {
                $queryBuilder->andWhere('IDENTITY(e.project) = :project')
                    ->setParameter('project', $arFilter['project']);
            }
        }

        if (isset($arFilter['activity']) && null !== $arFilter['activity']) {
            if (is_object($arFilter['activity'])) {
                $queryBuilder->andWhere('e.activity = :activity')
                    ->setParameter('activity', $arFilter['activity']);
            } else {
                $queryBuilder->andWhere('IDENTITY(e.activity) = :activity')
                    ->setParameter('activity', $arFilter['activity']);
            }
        }

        if (isset($arFilter['datestart']) && null !== $arFilter['datestart']) {
            $queryBuilder->andWhere('e.day >= :datestart')
                ->setParameter('datestart', $arFilter['datestart']);
        }

        if (isset($arFilter['dateend']) && null !== $arFilter['dateend']) {
            $queryBuilder->andWhere('e.day <= :dateend')
                ->setParameter('dateend', $arFilter['dateend']);
        }

        // Apply pagination
        $maxResults = isset($arFilter['maxResults']) ? (int) $arFilter['maxResults'] : 50;
        $page = isset($arFilter['page']) ? (int) $arFilter['page'] : 0;
        $offset = $page * $maxResults;

        $queryBuilder->setMaxResults($maxResults)
            ->setFirstResult($offset)
            ->orderBy('e.day', 'DESC')
            ->addOrderBy('e.start', 'DESC');

        return $queryBuilder->getQuery();
    }

    /**
     * Find overlapping entries for validation
     */
    public function findOverlappingEntries(
        $user,
        string $day,
        string $start,
        string $end,
        ?int $excludeId = null
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.day = :day')
            ->andWhere('(
                (e.start <= :start AND e.end > :start) OR
                (e.start < :end AND e.end >= :end) OR
                (e.start >= :start AND e.end <= :end)
            )')
            ->setParameter('user', $user)
            ->setParameter('day', $day)
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($excludeId) {
            $qb->andWhere('e.id != :excludeId')
                ->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get entries by user for specified days
     * 
     * @return array<int, Entry>
     */
    public function getEntriesByUser($user, int $days, bool $showFuture = false): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')
            ->leftJoin('e.customer', 'c')
            ->leftJoin('e.project', 'p')
            ->leftJoin('e.activity', 'a')
            
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.day', 'ASC')
            ->addOrderBy('e.start', 'ASC');

        // Calculate date range
        $today = new \DateTime();
        $startDate = clone $today;
        $startDate->sub(new \DateInterval('P' . $days . 'D'));
        
        $qb->andWhere('e.day >= :startDate')
           ->setParameter('startDate', $startDate->format('Y-m-d'));
           
        if (!$showFuture) {
            $qb->andWhere('e.day <= :endDate')
               ->setParameter('endDate', $today->format('Y-m-d'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find entries by date with filter support
     * 
     * @param array<string, string>|null $arSort
     * @return array<int, Entry>
     */
    public function findByDate(
        $user,
        int $year,
        ?int $month = null,
        $project = null,
        $customer = null,
        ?array $arSort = null
    ): array {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')
            ->leftJoin('e.customer', 'c')
            ->leftJoin('e.project', 'p')
            ->leftJoin('e.activity', 'a');
            
        // Use date range instead of YEAR function for DQL compatibility
        $startOfYear = sprintf('%04d-01-01', $year);
        $endOfYear = sprintf('%04d-12-31', $year);
        $qb->andWhere('e.day >= :startOfYear')
           ->andWhere('e.day <= :endOfYear')
           ->setParameter('startOfYear', $startOfYear)
           ->setParameter('endOfYear', $endOfYear);

        if ($user !== 0 && $user !== null) {
            $qb->andWhere('e.user = :user')
               ->setParameter('user', $user);
        }

        if (null !== $month && $month > 0) {
            // Use date range instead of MONTH function for DQL compatibility
            $startOfMonth = sprintf('%04d-%02d-01', $year, $month);
            $lastDay = (new \DateTime("$year-$month-01"))->format('t');
            $endOfMonth = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);
            $qb->andWhere('e.day >= :startOfMonth')
               ->andWhere('e.day <= :endOfMonth')
               ->setParameter('startOfMonth', $startOfMonth)
               ->setParameter('endOfMonth', $endOfMonth);
        }

        if (null !== $project) {
            $qb->andWhere('e.project = :project')
                ->setParameter('project', $project);
        }

        if (null !== $customer) {
            $qb->andWhere('e.customer = :customer')
                ->setParameter('customer', $customer);
        }

        // Apply sorting
        if (is_array($arSort) && !empty($arSort)) {
            foreach ($arSort as $field => $direction) {
                // Handle both string and boolean values for direction
                if (is_bool($direction)) {
                    $direction = $direction ? 'ASC' : 'DESC';
                } else {
                    $direction = strtoupper((string) $direction) === 'ASC' ? 'ASC' : 'DESC';
                }
                
                // Map logical field names to proper DQL expressions
                $dqlField = match ($field) {
                    'user.username' => 'u.username',
                    'entry.day' => 'e.day',
                    'entry.start' => 'e.start',
                    'entry.end' => 'e.end',
                    default => 'e.' . $field,
                };
                
                $qb->addOrderBy($dqlField, $direction);
            }
        } else {
            $qb->orderBy('e.day', 'DESC')
               ->addOrderBy('e.start', 'DESC');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Gets work by user for period (ported from OptimizedEntryRepository).
     * 
     * @return array{duration: int, count: int}
     */
    public function getWorkByUser(int $userId, Period $period = Period::DAY): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) as count, COALESCE(SUM(e.duration), 0) as duration')
            ->where('e.user = :user')
            ->setParameter('user', $userId);

        $this->applyPeriodFilter($qb, $period);

        $result = $qb->getQuery()->getSingleResult();

        if (!is_array($result)) {
            return ['duration' => 0, 'count' => 0];
        }

        return [
            'duration' => (int) ($result['duration'] ?? 0),
            'count' => (int) ($result['count'] ?? 0),
        ];
    }

    /**
     * Gets activities with time for a specific ticket.
     * 
     * @return array<int, array{name: string, total_time: int}>
     */
    public function getActivitiesWithTime(string $ticket): array
    {
        if (empty($ticket)) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        
        $sql = "SELECT a.name, SUM(e.duration) as total_time
                FROM entries e 
                LEFT JOIN activities a ON e.activity_id = a.id
                WHERE e.ticket = ?
                GROUP BY e.activity_id, a.name
                ORDER BY total_time DESC";

        $result = $connection->executeQuery($sql, [$ticket])->fetchAllAssociative();
        
        return array_map(function (array $row): array {
            return [
                'name' => $row['name'] ?? '',
                'total_time' => (int) ($row['total_time'] ?? 0),
            ];
        }, $result);
    }

    /**
     * Gets users with time for a specific ticket.
     * 
     * @return array<int, array{username: string, total_time: int}>
     */
    public function getUsersWithTime(string $ticket): array
    {
        if (empty($ticket)) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();
        
        $sql = "SELECT u.username, SUM(e.duration) as total_time
                FROM entries e 
                LEFT JOIN users u ON e.user_id = u.id
                WHERE e.ticket = ?
                GROUP BY e.user_id, u.username
                ORDER BY total_time DESC";

        $result = $connection->executeQuery($sql, [$ticket])->fetchAllAssociative();
        
        return array_map(function (array $row): array {
            return [
                'username' => $row['username'] ?? '',
                'total_time' => (int) ($row['total_time'] ?? 0),
            ];
        }, $result);
    }

    /**
     * Converts working days to calendar days (ported from OptimizedEntryRepository).
     */
    public function getCalendarDaysByWorkDays(int $workingDays): int
    {
        if ($workingDays <= 0) {
            return 0;
        }

        $days = 0;
        $date = $this->clock->today();
        $currentDayOfWeek = (int) $date->format('N');
        
        // For Monday (1), we need to count back through the weekend
        // to reach the previous Friday for the first working day
        if ($currentDayOfWeek === 1 && $workingDays > 0) {
            // Count the weekend (Saturday and Sunday) as calendar days
            $days = 2;
            // Move date to previous Friday
            $date = $date->sub(new \DateInterval('P3D'));
            --$workingDays;
            ++$days; // Count Friday as well
        }

        // Now handle the remaining working days
        while ($workingDays > 0) {
            $date = $date->sub(new \DateInterval('P1D'));
            ++$days;
            $dayOfWeek = (int) $date->format('N');

            if ($dayOfWeek < 6) { // Monday to Friday
                --$workingDays;
            }
        }

        return $days;
    }

    /**
     * Applies period filter to query builder.
     */
    private function applyPeriodFilter(QueryBuilder $qb, Period $period): void
    {
        $today = $this->clock->today();

        switch ($period) {
            case Period::DAY:
                $qb->andWhere('e.day = :today')
                    ->setParameter('today', $today->format('Y-m-d'));
                break;

            case Period::WEEK:
                $startOfWeek = clone $today;
                $startOfWeek = $startOfWeek->modify('monday this week') ?: $startOfWeek;
                $endOfWeek = clone $startOfWeek;
                $endOfWeek = $endOfWeek->modify('+6 days') ?: $endOfWeek;

                $qb->andWhere('e.day BETWEEN :start AND :end')
                    ->setParameter('start', $startOfWeek->format('Y-m-d'))
                    ->setParameter('end', $endOfWeek->format('Y-m-d'));
                break;

            case Period::MONTH:
                $startOfMonth = $today->format('Y-m-01');
                $lastDay = $today->format('t');
                $endOfMonth = $today->format('Y-m-') . $lastDay;
                
                $qb->andWhere('e.day >= :startOfMonth')
                    ->andWhere('e.day <= :endOfMonth')
                    ->setParameter('startOfMonth', $startOfMonth)
                    ->setParameter('endOfMonth', $endOfMonth);
                break;
        }
    }

    /**
     * Finds entries by recent days of user (ported from OptimizedEntryRepository).
     *
     * @return Entry[]
     */
    public function findByRecentDaysOfUser($user, int $days = 3): array
    {
        $fromDate = $this->calculateFromDate($days);

        return $this->findEntriesWithRelations()
            ->andWhere('e.user = :user')
            ->andWhere('e.day >= :fromDate')
            ->setParameter('user', $user)
            ->setParameter('fromDate', $fromDate->format('Y-m-d'))
            ->orderBy('e.day', 'ASC')
            ->addOrderBy('e.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Finds entries by user and ticket system for synchronization.
     *
     * @return Entry[]
     */
    public function findByUserAndTicketSystemToSync(int $userId, int $ticketSystemId, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')
            ->leftJoin('e.customer', 'c')
            ->leftJoin('e.project', 'p')
            ->leftJoin('e.activity', 'a')
            
            ->where('e.user = :userId')
            ->andWhere('p.ticketSystem = :ticketSystemId')
            ->andWhere('e.ticket IS NOT NULL')
            ->andWhere('e.ticket != :emptyString')
            ->andWhere('e.internalJiraTicketId IS NULL OR e.internalJiraTicketId = :emptyString')
            ->setParameter('userId', $userId)
            ->setParameter('ticketSystemId', $ticketSystemId)
            ->setParameter('emptyString', '')
            ->orderBy('e.day', 'DESC')
            ->addOrderBy('e.start', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Gets entry summary data for display.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getEntrySummary(int $entryId, int $userId, array $data): array
    {
        $entry = $this->find($entryId);
        if (!$entry instanceof Entry) {
            return $data;
        }

        $connection = $this->getEntityManager()->getConnection();

        // Get customer summary
        if ($entry->getCustomer()) {
            $sql = "SELECT COUNT(e.id) as entries, SUM(e.duration) as total,
                          SUM(CASE WHEN e.user_id = ? THEN e.duration ELSE 0 END) as own,
                          c.name as name
                   FROM entries e 
                   LEFT JOIN customers c ON e.customer_id = c.id
                   WHERE e.customer_id = ?";
            
            $result = $connection->executeQuery($sql, [$userId, $entry->getCustomer()->getId()])->fetchAssociative();
            if ($result) {
                $data['customer'] = [
                    'scope' => 'customer',
                    'name' => $result['name'] ?? '',
                    'entries' => (int) ($result['entries'] ?? 0),
                    'total' => (int) ($result['total'] ?? 0),
                    'own' => (int) ($result['own'] ?? 0),
                    'estimation' => 0,
                ];
            }
        }

        // Get project summary
        if ($entry->getProject()) {
            $sql = "SELECT COUNT(e.id) as entries, SUM(e.duration) as total,
                          SUM(CASE WHEN e.user_id = ? THEN e.duration ELSE 0 END) as own,
                          p.name as name, p.estimation as estimation
                   FROM entries e 
                   LEFT JOIN projects p ON e.project_id = p.id
                   WHERE e.project_id = ?";
            
            $result = $connection->executeQuery($sql, [$userId, $entry->getProject()->getId()])->fetchAssociative();
            if ($result) {
                $data['project'] = [
                    'scope' => 'project',
                    'name' => $result['name'] ?? '',
                    'entries' => (int) ($result['entries'] ?? 0),
                    'total' => (int) ($result['total'] ?? 0),
                    'own' => (int) ($result['own'] ?? 0),
                    'estimation' => (int) ($result['estimation'] ?? 0),
                ];
            }
        }

        // Get activity summary
        if ($entry->getActivity()) {
            $sql = "SELECT COUNT(e.id) as entries, SUM(e.duration) as total,
                          SUM(CASE WHEN e.user_id = ? THEN e.duration ELSE 0 END) as own,
                          a.name as name
                   FROM entries e 
                   LEFT JOIN activities a ON e.activity_id = a.id
                   WHERE e.activity_id = ?";
            
            $result = $connection->executeQuery($sql, [$userId, $entry->getActivity()->getId()])->fetchAssociative();
            if ($result) {
                $data['activity'] = [
                    'scope' => 'activity',
                    'name' => $result['name'] ?? '',
                    'entries' => (int) ($result['entries'] ?? 0),
                    'total' => (int) ($result['total'] ?? 0),
                    'own' => (int) ($result['own'] ?? 0),
                    'estimation' => 0,
                ];
            }
        }

        // Get ticket summary
        if (!empty($entry->getTicket())) {
            $sql = "SELECT COUNT(e.id) as entries, SUM(e.duration) as total,
                          SUM(CASE WHEN e.user_id = ? THEN e.duration ELSE 0 END) as own
                   FROM entries e 
                   WHERE e.ticket = ?";
            
            $result = $connection->executeQuery($sql, [$userId, $entry->getTicket()])->fetchAssociative();
            if ($result) {
                $data['ticket'] = [
                    'scope' => 'ticket',
                    'name' => $entry->getTicket(),
                    'entries' => (int) ($result['entries'] ?? 0),
                    'total' => (int) ($result['total'] ?? 0),
                    'own' => (int) ($result['own'] ?? 0),
                    'estimation' => 0,
                ];
            }
        }

        return $data;
    }

    /**
     * Finds entries by day.
     *
     * @return Entry[]
     */
    public function findByDay(int $userId, string $day): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')
            ->leftJoin('e.customer', 'c')
            ->leftJoin('e.project', 'p')
            ->leftJoin('e.activity', 'a')
            
            ->where('e.user = :userId')
            ->andWhere('e.day = :day')
            ->setParameter('userId', $userId)
            ->setParameter('day', $day)
            ->orderBy('e.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculates from date based on working days.
     */
    private function calculateFromDate(int $workingDays): \DateTimeInterface
    {
        $today = $this->clock->today();

        if ($workingDays <= 0) {
            return $today;
        }

        $date = clone $today;
        $daysToSubtract = $this->getCalendarDaysByWorkDays($workingDays);
        $date->sub(new \DateInterval('P' . $daysToSubtract . 'D'));

        return $date;
    }

    /**
     * Finds entries by filter array.
     *
     * @param array<string, mixed> $arFilter
     * @return Entry[]
     */
    public function findByFilterArray(array $arFilter): array
    {
        $qb = $this->findEntriesWithRelations();

        // Apply filters similar to queryByFilterArray but return results directly
        if (isset($arFilter['customer']) && null !== $arFilter['customer']) {
            $qb->andWhere('e.customer = :customer')
                ->setParameter('customer', $arFilter['customer']);
        }

        if (isset($arFilter['project']) && null !== $arFilter['project']) {
            $qb->andWhere('e.project = :project')
                ->setParameter('project', $arFilter['project']);
        }

        if (isset($arFilter['activity']) && null !== $arFilter['activity']) {
            $qb->andWhere('e.activity = :activity')
                ->setParameter('activity', $arFilter['activity']);
        }

        if (isset($arFilter['user']) && null !== $arFilter['user']) {
            $qb->andWhere('e.user = :user')
                ->setParameter('user', $arFilter['user']);
        }

        if (isset($arFilter['datestart']) && null !== $arFilter['datestart']) {
            $qb->andWhere('e.day >= :datestart')
                ->setParameter('datestart', $arFilter['datestart']);
        }

        if (isset($arFilter['dateend']) && null !== $arFilter['dateend']) {
            $qb->andWhere('e.day <= :dateend')
                ->setParameter('dateend', $arFilter['dateend']);
        }

        // Apply limit if specified
        if (isset($arFilter['maxResults'])) {
            $qb->setMaxResults((int) $arFilter['maxResults']);
        }

        // Apply pagination
        if (isset($arFilter['page'])) {
            $page = (int) $arFilter['page'];
            $maxResults = isset($arFilter['maxResults']) ? (int) $arFilter['maxResults'] : 50;
            $offset = $page * $maxResults;
            $qb->setFirstResult($offset);
        }

        $qb->orderBy('e.day', 'DESC')
           ->addOrderBy('e.start', 'DESC');

        return $qb->getQuery()->getResult();
    }
}