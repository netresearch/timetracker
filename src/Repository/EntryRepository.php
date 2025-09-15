<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\DatabaseResultDto;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\Period;
use App\Service\ClockInterface;
use App\Service\Util\TimeCalculationService;
use DateInterval;
use DateTime;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;

use function assert;
use function in_array;
use function is_array;
use function is_bool;
use function is_object;
use function is_string;
use function sprintf;

/**
 * @extends ServiceEntityRepository<Entry>
 */
class EntryRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly TimeCalculationService $timeCalculationService,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct($registry, Entry::class);
    }

    /**
     * Priority 2: Add explicit type-safe repository method for mixed type handling.
     */
    public function findOneById(int $id): ?Entry
    {
        $result = $this->find($id);

        return $result instanceof Entry ? $result : null;
    }

    /**
     * Get all entries for specific day.
     *
     * @return Entry[]
     */
    public function getEntriesForDay(User $user, string $day): array
    {
        $result = $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.day = :day')
            ->setParameter('user', $user)
            ->setParameter('day', $day)
            ->getQuery()
            ->getResult()
        ;

        assert(is_array($result) && array_is_list($result));

        return $result;
    }

    /**
     * Get entries for a specific month.
     *
     * @return Entry[]
     */
    public function getEntriesForMonth(User $user, string $startDate, string $endDate): array
    {
        $result = $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.day >= :startDate')
            ->andWhere('e.day <= :endDate')
            ->setParameter('user', $user)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('e.day', 'ASC')
            ->addOrderBy('e.start', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        assert(is_array($result) && array_is_list($result));

        return $result;
    }

    /**
     * Count entries for a specific user.
     */
    public function getCountByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    /**
     * Delete entries for a specific user.
     */
    public function deleteByUserId(User $user): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Delete entries for a specific activity.
     */
    public function deleteByActivityId(Activity $activity): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.activity = :activity')
            ->setParameter('activity', $activity)
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Delete entries for a specific project.
     */
    public function deleteByProjectId(Project $project): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Delete entries for a specific customer.
     */
    public function deleteByCustomerId(Customer $customer): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.customer = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->execute()
        ;
    }

    /**
     * Get entries with related entities for efficient data loading.
     *
     * @param array<string, mixed> $conditions
     */
    public function findEntriesWithRelations(array $conditions = []): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')
            ->leftJoin('e.customer', 'c')
            ->leftJoin('e.project', 'p')
            ->leftJoin('e.activity', 'a')
        ;

        foreach ($conditions as $field => $value) {
            $queryBuilder->andWhere(sprintf('e.%s = :%s', $field, $field))
                ->setParameter($field, $value)
            ;
        }

        return $queryBuilder;
    }

    /**
     * Find entries by multiple IDs efficiently.
     *
     * @param int[] $ids
     *
     * @return Entry[]
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $result = $this->createQueryBuilder('e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult()
        ;

        assert(is_array($result) && array_is_list($result));

        return $result;
    }

    /**
     * Get total duration for entries matching conditions.
     *
     * @param array<string, mixed> $conditions
     */
    public function getTotalDuration(array $conditions = []): float
    {
        $queryBuilder = $this->createQueryBuilder('e')
            ->select('SUM(e.duration)')
        ;

        foreach ($conditions as $field => $value) {
            $queryBuilder->andWhere(sprintf('e.%s = :%s', $field, $field))
                ->setParameter($field, $value)
            ;
        }

        return (float) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Check if entry exists with given conditions.
     *
     * @param array<string, mixed> $conditions
     */
    public function existsWithConditions(array $conditions): bool
    {
        $queryBuilder = $this->createQueryBuilder('e')
            ->select('1')
        ;

        foreach ($conditions as $field => $value) {
            $queryBuilder->andWhere(sprintf('e.%s = :%s', $field, $field))
                ->setParameter($field, $value)
            ;
        }

        return null !== $queryBuilder->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Get database-agnostic date/time formatting functions.
     *
     * @return array{dateFormat: string, timeFormat: string}
     */
    private function getDateTimeFormats(): array
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        // Check for MySQL/MariaDB platforms (MariaDB is detected as MySQL platform)
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
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
     * Get database-agnostic SQL functions for date/year/month operations.
     *
     * @return array{yearFunction: string, monthFunction: string, weekFunction: string, concatFunction: string, ifFunction: string}
     */
    private function getDatabaseSpecificFunctions(): array
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        // Check for MySQL/MariaDB platforms
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
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
     * Generate database-agnostic YEAR expression.
     */
    protected function generateYearExpression(string $field): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        // Check for MySQL/MariaDB platforms
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
            return sprintf('YEAR(%s)', $field);
        }

        return sprintf("strftime('%%Y', %s)", $field);
    }

    /**
     * Generate database-agnostic MONTH expression.
     */
    protected function generateMonthExpression(string $field): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        // Check for MySQL/MariaDB platforms
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
            return sprintf('MONTH(%s)', $field);
        }

        return sprintf("strftime('%%m', %s)", $field);
    }

    /**
     * Generate database-agnostic WEEK expression.
     */
    protected function generateWeekExpression(string $field): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        // Check for MySQL/MariaDB platforms
        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform
            || $platform instanceof \Doctrine\DBAL\Platforms\MariaDBPlatform) {
            return sprintf('WEEK(%s, 1)', $field);
        }

        return sprintf("strftime('%%W', %s)", $field);
    }

    /**
     * Execute raw SQL with optimized direct connection approach.
     *
     * @return list<array<string, mixed>>
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
     * Get entries with pagination and filtering.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<int, Entry>
     */
    public function getFilteredEntries(
        array $filters = [],
        int $offset = 0,
        int $limit = 50,
        string $orderBy = 'day',
        string $orderDirection = 'DESC',
    ): array {
        $queryBuilder = $this->findEntriesWithRelations();

        // Apply filters
        foreach ($filters as $field => $value) {
            if (null !== $value && '' !== $value) {
                if (in_array($field, ['startDate', 'endDate'], true)) {
                    $operator = 'startDate' === $field ? '>=' : '<=';
                    $fieldName = 'day';
                    $queryBuilder->andWhere(sprintf('e.%s %s :%s', $fieldName, $operator, $field))
                        ->setParameter($field, $value)
                    ;
                } else {
                    $queryBuilder->andWhere(sprintf('e.%s = :%s', $field, $field))
                        ->setParameter($field, $value)
                    ;
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

            $queryBuilder->orderBy('e.' . $orderBy, $orderDirection);
        }

        // Apply pagination
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        if ($offset > 0) {
            $queryBuilder->setFirstResult($offset);
        }

        $result = $queryBuilder->getQuery()->getResult();

        assert(is_array($result) && array_is_list($result));

        return $result;
    }

    /**
     * Get summary data for reporting.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function getSummaryData(array $filters = []): array
    {
        $queryBuilder = $this->createQueryBuilder('e')
            ->select([
                'COUNT(e.id) as entryCount',
                'SUM(e.duration) as totalDuration',
                'AVG(e.duration) as avgDuration',
                'MIN(e.day) as minDate',
                'MAX(e.day) as maxDate',
            ])
        ;

        foreach ($filters as $field => $value) {
            if (null !== $value && '' !== $value) {
                if (in_array($field, ['startDate', 'endDate'], true)) {
                    $operator = 'startDate' === $field ? '>=' : '<=';
                    $fieldName = 'day';
                    $queryBuilder->andWhere(sprintf('e.%s %s :%s', $fieldName, $operator, $field))
                        ->setParameter($field, $value)
                    ;
                } else {
                    $queryBuilder->andWhere(sprintf('e.%s = :%s', $field, $field))
                        ->setParameter($field, $value)
                    ;
                }
            }
        }

        $rawResult = $queryBuilder->getQuery()->getSingleResult();

        /** @var array<string, mixed> $result */
        $result = is_array($rawResult) ? $rawResult : [];

        // Ensure numeric values with safe access
        $entryCountValue = $result['entryCount'] ?? 0;
        $entryCount = is_numeric($entryCountValue) ? (int) $entryCountValue : 0;
        $totalDurationValue = $result['totalDuration'] ?? 0;
        $totalDuration = is_numeric($totalDurationValue) ? (float) $totalDurationValue : 0.0;
        $avgDurationValue = $result['avgDuration'] ?? 0;
        $avgDuration = is_numeric($avgDurationValue) ? (float) $avgDurationValue : 0.0;

        return [
            'entryCount' => $entryCount,
            'totalDuration' => $totalDuration,
            'avgDuration' => $avgDuration,
            'minDate' => $result['minDate'] ?? null,
            'maxDate' => $result['maxDate'] ?? null,
        ];
    }

    /**
     * Get time summary grouped by criteria (for charts/reports).
     *
     * @param array<string, mixed> $filters
     *
     * @return list<array<string, mixed>>
     */
    public function getTimeSummaryByPeriod(
        string $period,
        array $filters = [],
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        $connection = $this->getEntityManager()->getConnection();
        $functions = $this->getDatabaseSpecificFunctions();

        // Period function mapping
        $periodFunctions = [
            'year' => $functions['yearFunction'],
            'month' => $functions['monthFunction'],
            'week' => $functions['weekFunction'],
        ];

        if (!isset($periodFunctions[$period])) {
            throw new InvalidArgumentException('Invalid period: ' . $period);
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
            $sql .= ' AND e.day >= ?';
            $params[$paramCounter++] = $startDate;
        }

        if ($endDate) {
            $sql .= ' AND e.day <= ?';
            $params[$paramCounter++] = $endDate;
        }

        // Add additional filters
        foreach ($filters as $field => $value) {
            if (null !== $value && '' !== $value) {
                $sql .= sprintf(' AND e.%s = ?', $field);
                $params[$paramCounter++] = $value;
            }
        }

        $sql .= sprintf(' GROUP BY %s ORDER BY period_value', $groupByFunction);

        $statement = $connection->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        return $statement->executeQuery()->fetchAllAssociative();
    }

    /**
     * Bulk update entries.
     *
     * @param array<int>           $entryIds
     * @param array<string, mixed> $updateData
     */
    public function bulkUpdate(array $entryIds, array $updateData): int
    {
        if ([] === $entryIds || [] === $updateData) {
            return 0;
        }

        $queryBuilder = $this->getEntityManager()->createQueryBuilder()
            ->update(Entry::class, 'e')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $entryIds)
        ;

        foreach ($updateData as $field => $value) {
            $queryBuilder->set('e.' . $field, ':' . $field)
                ->setParameter($field, $value)
            ;
        }

        $result = $queryBuilder->getQuery()->execute();

        return is_numeric($result) ? (int) $result : 0;
    }

    /**
     * Query entries by filter array for pagination.
     *
     * @param array<string, mixed> $arFilter
     *
     * @phpstan-return \Doctrine\ORM\Query<int, Entry>
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
        if (isset($arFilter['customer']) && '' !== $arFilter['customer']) {
            if (is_object($arFilter['customer'])) {
                $queryBuilder->andWhere('e.customer = :customer')
                    ->setParameter('customer', $arFilter['customer'])
                ;
            } else {
                $queryBuilder->andWhere('IDENTITY(e.customer) = :customer')
                    ->setParameter('customer', $arFilter['customer'])
                ;
            }
        }

        if (isset($arFilter['project']) && '' !== $arFilter['project']) {
            if (is_object($arFilter['project'])) {
                $queryBuilder->andWhere('e.project = :project')
                    ->setParameter('project', $arFilter['project'])
                ;
            } else {
                $queryBuilder->andWhere('IDENTITY(e.project) = :project')
                    ->setParameter('project', $arFilter['project'])
                ;
            }
        }

        if (isset($arFilter['activity']) && '' !== $arFilter['activity']) {
            if (is_object($arFilter['activity'])) {
                $queryBuilder->andWhere('e.activity = :activity')
                    ->setParameter('activity', $arFilter['activity'])
                ;
            } else {
                $queryBuilder->andWhere('IDENTITY(e.activity) = :activity')
                    ->setParameter('activity', $arFilter['activity'])
                ;
            }
        }

        if (isset($arFilter['datestart']) && '' !== $arFilter['datestart']) {
            $queryBuilder->andWhere('e.day >= :datestart')
                ->setParameter('datestart', $arFilter['datestart'])
            ;
        }

        if (isset($arFilter['dateend']) && '' !== $arFilter['dateend']) {
            $queryBuilder->andWhere('e.day <= :dateend')
                ->setParameter('dateend', $arFilter['dateend'])
            ;
        }

        // Apply pagination with safe casting
        $maxResults = isset($arFilter['maxResults']) && is_numeric($arFilter['maxResults']) ? (int) $arFilter['maxResults'] : 50;
        $page = isset($arFilter['page']) && is_numeric($arFilter['page']) ? (int) $arFilter['page'] : 0;
        $offset = $page * $maxResults;

        $queryBuilder->setMaxResults($maxResults)
            ->setFirstResult($offset)
            ->orderBy('e.day', 'DESC')
            ->addOrderBy('e.start', 'DESC')
        ;

        /* @phpstan-ignore-next-line */
        return $queryBuilder->getQuery();
    }

    /**
     * Find overlapping entries for validation.
     *
     * @return array<int, Entry>
     */
    public function findOverlappingEntries(
        User $user,
        string $day,
        string $start,
        string $end,
        ?int $excludeId = null,
    ): array {
        $queryBuilder = $this->createQueryBuilder('e')
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
            ->setParameter('end', $end)
        ;

        if ($excludeId) {
            $queryBuilder->andWhere('e.id != :excludeId')
                ->setParameter('excludeId', $excludeId)
            ;
        }

        $result = $queryBuilder->getQuery()->getResult();

        assert(is_array($result) && array_is_list($result));

        return $result;
    }

    /**
     * Get entries by user for specified days.
     *
     * @return array<int, Entry>
     */
    public function getEntriesByUser(User $user, int $days, bool $showFuture = false): array
    {
        $queryBuilder = $this->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')
            ->leftJoin('e.customer', 'c')
            ->leftJoin('e.project', 'p')
            ->leftJoin('e.activity', 'a')

            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->orderBy('e.day', 'ASC')
            ->addOrderBy('e.start', 'ASC')
        ;

        // Calculate date range
        $today = new DateTime();
        $startDate = clone $today;
        $startDate->sub(new DateInterval('P' . $days . 'D'));

        $queryBuilder->andWhere('e.day >= :startDate')
            ->setParameter('startDate', $startDate->format('Y-m-d'))
        ;

        if (!$showFuture) {
            $queryBuilder->andWhere('e.day <= :endDate')
                ->setParameter('endDate', $today->format('Y-m-d'))
            ;
        }

        $result = $queryBuilder->getQuery()->getResult();
        assert(is_array($result) && array_is_list($result));

        /* @var array<int, Entry> $result */
        return $result;
    }

    /**
     * Find entries by date with filter support.
     *
     * @param array<string, string>|null $arSort
     *
     * @return array<int, Entry>
     */
    public function findByDate(
        int $user,
        int $year,
        ?int $month = null,
        ?int $project = null,
        ?int $customer = null,
        ?array $arSort = null,
    ): array {
        $queryBuilder = $this->findEntriesWithRelations();

        // Use date range instead of YEAR function for DQL compatibility
        $startOfYear = sprintf('%04d-01-01', $year);
        $endOfYear = sprintf('%04d-12-31', $year);
        $queryBuilder->andWhere('e.day >= :startOfYear')
            ->andWhere('e.day <= :endOfYear')
            ->setParameter('startOfYear', $startOfYear)
            ->setParameter('endOfYear', $endOfYear)
        ;

        if (0 !== $user) {
            $queryBuilder->andWhere('e.user = :user')
                ->setParameter('user', $user)
            ;
        }

        if (null !== $month && $month > 0) {
            // Use date range instead of MONTH function for DQL compatibility
            $startOfMonth = sprintf('%04d-%02d-01', $year, $month);
            $lastDay = new DateTime(sprintf('%d-%d-01', $year, $month))->format('t');
            $endOfMonth = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);
            $queryBuilder->andWhere('e.day >= :startOfMonth')
                ->andWhere('e.day <= :endOfMonth')
                ->setParameter('startOfMonth', $startOfMonth)
                ->setParameter('endOfMonth', $endOfMonth)
            ;
        }

        if (null !== $project) {
            $queryBuilder->andWhere('e.project = :project')
                ->setParameter('project', $project)
            ;
        }

        if (null !== $customer) {
            $queryBuilder->andWhere('e.customer = :customer')
                ->setParameter('customer', $customer)
            ;
        }

        // Apply sorting
        if (is_array($arSort) && [] !== $arSort) {
            foreach ($arSort as $field => $direction) {
                // Handle both string and boolean values for direction
                if (is_bool($direction)) {
                    $direction = $direction ? 'ASC' : 'DESC';
                } else {
                    $direction = 'ASC' === strtoupper((string) $direction) ? 'ASC' : 'DESC';
                }

                // Map logical field names to proper DQL expressions
                $dqlField = match ($field) {
                    'user.username' => 'u.username',
                    'entry.day' => 'e.day',
                    'entry.start' => 'e.start',
                    'entry.end' => 'e.end',
                    default => 'e.' . $field,
                };

                $queryBuilder->addOrderBy($dqlField, $direction);
            }
        } else {
            $queryBuilder->orderBy('e.day', 'DESC')
                ->addOrderBy('e.start', 'DESC')
            ;
        }

        $result = $queryBuilder->getQuery()->getResult();
        assert(is_array($result) && array_is_list($result));

        /* @var array<int, Entry> $result */
        return $result;
    }

    /**
     * Find entries by date with pagination for memory efficiency.
     *
     * @param array<string, string>|null $arSort
     *
     * @return array<int, Entry>
     */
    public function findByDatePaginated(
        int $user,
        int $year,
        ?int $month = null,
        ?int $project = null,
        ?int $customer = null,
        ?array $arSort = null,
        int $offset = 0,
        int $limit = 1000,
    ): array {
        $queryBuilder = $this->findEntriesWithRelations();

        // Use date range instead of YEAR function for DQL compatibility
        $startOfYear = sprintf('%04d-01-01', $year);
        $endOfYear = sprintf('%04d-12-31', $year);
        $queryBuilder->andWhere('e.day >= :startOfYear')
            ->andWhere('e.day <= :endOfYear')
            ->setParameter('startOfYear', $startOfYear)
            ->setParameter('endOfYear', $endOfYear)
        ;

        if (0 !== $user) {
            $queryBuilder->andWhere('e.user = :user')
                ->setParameter('user', $user)
            ;
        }

        if (null !== $month && $month > 0) {
            // Use date range instead of MONTH function for DQL compatibility
            $startOfMonth = sprintf('%04d-%02d-01', $year, $month);
            $lastDay = new DateTime(sprintf('%d-%d-01', $year, $month))->format('t');
            $endOfMonth = sprintf('%04d-%02d-%02d', $year, $month, $lastDay);
            $queryBuilder->andWhere('e.day >= :startOfMonth')
                ->andWhere('e.day <= :endOfMonth')
                ->setParameter('startOfMonth', $startOfMonth)
                ->setParameter('endOfMonth', $endOfMonth)
            ;
        }

        if (null !== $project) {
            $queryBuilder->andWhere('e.project = :project')
                ->setParameter('project', $project)
            ;
        }

        if (null !== $customer) {
            $queryBuilder->andWhere('e.customer = :customer')
                ->setParameter('customer', $customer)
            ;
        }

        // Apply sorting
        if (is_array($arSort) && [] !== $arSort) {
            foreach ($arSort as $field => $direction) {
                if (!is_string($field)) {
                    continue;
                }
                if (!is_string($direction)) {
                    continue;
                }
                $direction = 'ASC' === strtoupper($direction) ? 'ASC' : 'DESC';

                // Map logical field names to proper DQL expressions
                $dqlField = match ($field) {
                    'user.username' => 'u.username',
                    'entry.day' => 'e.day',
                    'entry.start' => 'e.start',
                    'entry.end' => 'e.end',
                    default => 'e.' . $field,
                };

                $queryBuilder->addOrderBy($dqlField, $direction);
            }
        } else {
            $queryBuilder->orderBy('e.day', 'DESC')
                ->addOrderBy('e.start', 'DESC')
            ;
        }

        // Apply pagination
        $queryBuilder->setFirstResult($offset)
           ->setMaxResults($limit);

        $result = $queryBuilder->getQuery()->getResult();
        assert(is_array($result) && array_is_list($result));

        /* @var array<int, Entry> $result */
        return $result;
    }

    /**
     * Gets work by user for period (ported from OptimizedEntryRepository).
     *
     * @return array{duration: int, count: int}
     */
    public function getWorkByUser(int $userId, Period $period = Period::DAY): array
    {
        $queryBuilder = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) as count, COALESCE(SUM(e.duration), 0) as duration')
            ->where('e.user = :user')
            ->setParameter('user', $userId)
        ;

        $this->applyPeriodFilter($queryBuilder, $period);

        $result = $queryBuilder->getQuery()->getSingleResult();

        if (!is_array($result)) {
            return ['duration' => 0, 'count' => 0];
        }

        $duration = $result['duration'] ?? 0;
        $count = $result['count'] ?? 0;

        assert(is_numeric($duration));
        assert(is_numeric($count));

        return [
            'duration' => (int) $duration,
            'count' => (int) $count,
        ];
    }

    /**
     * Gets activities with time for a specific ticket.
     *
     * @return array<int, array{name: string, total_time: int}>
     */
    public function getActivitiesWithTime(string $ticket): array
    {
        if ('' === $ticket || '0' === $ticket) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();

        $sql = 'SELECT a.name, SUM(e.duration) as total_time
                FROM entries e
                LEFT JOIN activities a ON e.activity_id = a.id
                WHERE e.ticket = ?
                GROUP BY e.activity_id, a.name
                ORDER BY total_time DESC';

        $result = $connection->executeQuery($sql, [$ticket])->fetchAllAssociative();

        return array_map(static function (array $row): array {
            $name = $row['name'];
            $totalTime = $row['total_time'];

            return [
                'name' => is_string($name) ? $name : '',
                'total_time' => is_numeric($totalTime) ? (int) $totalTime : 0,
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
        if ('' === $ticket || '0' === $ticket) {
            return [];
        }

        $connection = $this->getEntityManager()->getConnection();

        $sql = 'SELECT u.username, SUM(e.duration) as total_time
                FROM entries e
                LEFT JOIN users u ON e.user_id = u.id
                WHERE e.ticket = ?
                GROUP BY e.user_id, u.username
                ORDER BY total_time DESC';

        $result = $connection->executeQuery($sql, [$ticket])->fetchAllAssociative();

        return array_map(static function (array $row): array {
            $username = $row['username'];
            $totalTime = $row['total_time'];

            return [
                'username' => is_string($username) ? $username : '',
                'total_time' => is_numeric($totalTime) ? (int) $totalTime : 0,
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
        if (1 === $currentDayOfWeek) {
            // Count the weekend (Saturday and Sunday) as calendar days
            $days = 2;
            // Move date to previous Friday
            $date = $date->sub(new DateInterval('P3D'));
            --$workingDays;
            ++$days; // Count Friday as well
        }

        // Now handle the remaining working days
        while ($workingDays > 0) {
            $date = $date->sub(new DateInterval('P1D'));
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
    private function applyPeriodFilter(QueryBuilder $queryBuilder, Period $period): void
    {
        $today = $this->clock->today();

        switch ($period) {
            case Period::DAY:
                $queryBuilder->andWhere('e.day = :today')
                    ->setParameter('today', $today->format('Y-m-d'))
                ;
                break;

            case Period::WEEK:
                $startOfWeek = clone $today;
                $startOfWeek = $startOfWeek->modify('monday this week') ?: $startOfWeek;
                $endOfWeek = clone $startOfWeek;
                $endOfWeek = $endOfWeek->modify('+6 days') ?: $endOfWeek;

                $queryBuilder->andWhere('e.day BETWEEN :start AND :end')
                    ->setParameter('start', $startOfWeek->format('Y-m-d'))
                    ->setParameter('end', $endOfWeek->format('Y-m-d'))
                ;
                break;

            case Period::MONTH:
                $startOfMonth = $today->format('Y-m-01');
                $lastDay = $today->format('t');
                $endOfMonth = $today->format('Y-m-') . $lastDay;

                $queryBuilder->andWhere('e.day >= :startOfMonth')
                    ->andWhere('e.day <= :endOfMonth')
                    ->setParameter('startOfMonth', $startOfMonth)
                    ->setParameter('endOfMonth', $endOfMonth)
                ;
                break;
        }
    }

    /**
     * Finds entries by recent days of user (ported from OptimizedEntryRepository).
     *
     * @return Entry[]
     */
    public function findByRecentDaysOfUser(User $user, int $days = 3): array
    {
        $fromDate = $this->calculateFromDate($days);

        $result = $this->findEntriesWithRelations()
            ->andWhere('e.user = :user')
            ->andWhere('e.day >= :fromDate')
            ->setParameter('user', $user)
            ->setParameter('fromDate', $fromDate->format('Y-m-d'))
            ->orderBy('e.day', 'ASC')
            ->addOrderBy('e.start', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        assert(is_array($result) && array_is_list($result));

        /* @var array<Entry> $result */
        return $result;
    }

    /**
     * Finds entries by user and ticket system for synchronization.
     *
     * @return Entry[]
     */
    public function findByUserAndTicketSystemToSync(int $userId, int $ticketSystemId, int $limit = 50): array
    {
        $result = $this->createQueryBuilder('e')
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
            ->getResult()
        ;

        assert(is_array($result) && array_is_list($result));

        return $result;
    }

    /**
     * Gets entry summary data for display.
     *
     * @param array<string, array<string, mixed>> $data
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
        if ($entry->getCustomer() instanceof Customer) {
            $sql = 'SELECT COUNT(e.id) as entries, SUM(e.duration) as total,
                          SUM(CASE WHEN e.user_id = ? THEN e.duration ELSE 0 END) as own,
                          c.name as name
                   FROM entries e
                   LEFT JOIN customers c ON e.customer_id = c.id
                   WHERE e.customer_id = ?';

            $result = $connection->executeQuery($sql, [$userId, $entry->getCustomer()->getId()])->fetchAssociative();
            if ($result) {
                $entries = $result['entries'] ?? 0;
                $total = $result['total'] ?? 0;
                $own = $result['own'] ?? 0;

                assert(is_numeric($entries));
                assert(is_numeric($total));
                assert(is_numeric($own));

                $data['customer'] = [
                    'scope' => 'customer',
                    'name' => $result['name'] ?? '',
                    'entries' => (int) $entries,
                    'total' => (int) $total,
                    'own' => (int) $own,
                    'estimation' => 0,
                ];
            }
        }

        // Get project summary
        if ($entry->getProject() instanceof Project) {
            $sql = 'SELECT COUNT(e.id) as entries, SUM(e.duration) as total,
                          SUM(CASE WHEN e.user_id = ? THEN e.duration ELSE 0 END) as own,
                          p.name as name, p.estimation as estimation
                   FROM entries e
                   LEFT JOIN projects p ON e.project_id = p.id
                   WHERE e.project_id = ?';

            $result = $connection->executeQuery($sql, [$userId, $entry->getProject()->getId()])->fetchAssociative();
            if ($result) {
                $entries = $result['entries'] ?? 0;
                $total = $result['total'] ?? 0;
                $own = $result['own'] ?? 0;
                $estimation = $result['estimation'] ?? 0;

                assert(is_numeric($entries));
                assert(is_numeric($total));
                assert(is_numeric($own));
                assert(is_numeric($estimation));

                $data['project'] = [
                    'scope' => 'project',
                    'name' => $result['name'] ?? '',
                    'entries' => (int) $entries,
                    'total' => (int) $total,
                    'own' => (int) $own,
                    'estimation' => (int) $estimation,
                ];
            }
        }

        // Get activity summary
        if ($entry->getActivity() instanceof Activity) {
            $sql = 'SELECT COUNT(e.id) as entries, SUM(e.duration) as total,
                          SUM(CASE WHEN e.user_id = ? THEN e.duration ELSE 0 END) as own,
                          a.name as name
                   FROM entries e
                   LEFT JOIN activities a ON e.activity_id = a.id
                   WHERE e.activity_id = ?';

            $result = $connection->executeQuery($sql, [$userId, $entry->getActivity()->getId()])->fetchAssociative();
            if ($result) {
                $entries = $result['entries'] ?? 0;
                $total = $result['total'] ?? 0;
                $own = $result['own'] ?? 0;

                assert(is_numeric($entries));
                assert(is_numeric($total));
                assert(is_numeric($own));

                $data['activity'] = [
                    'scope' => 'activity',
                    'name' => $result['name'] ?? '',
                    'entries' => (int) $entries,
                    'total' => (int) $total,
                    'own' => (int) $own,
                    'estimation' => 0,
                ];
            }
        }

        // Get ticket summary
        if (!in_array($entry->getTicket(), ['', '0'], true)) {
            $sql = 'SELECT COUNT(e.id) as entries, SUM(e.duration) as total,
                          SUM(CASE WHEN e.user_id = ? THEN e.duration ELSE 0 END) as own
                   FROM entries e
                   WHERE e.ticket = ?';

            $result = $connection->executeQuery($sql, [$userId, $entry->getTicket()])->fetchAssociative();
            if ($result) {
                $entries = $result['entries'] ?? 0;
                $total = $result['total'] ?? 0;
                $own = $result['own'] ?? 0;

                assert(is_numeric($entries));
                assert(is_numeric($total));
                assert(is_numeric($own));

                $data['ticket'] = [
                    'scope' => 'ticket',
                    'name' => $entry->getTicket(),
                    'entries' => (int) $entries,
                    'total' => (int) $total,
                    'own' => (int) $own,
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
        $result = $this->createQueryBuilder('e')
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
            ->getResult()
        ;

        assert(is_array($result) && array_is_list($result));

        return $result;
    }

    /**
     * Calculates from date based on working days.
     */
    private function calculateFromDate(int $workingDays): DateTimeInterface
    {
        $today = $this->clock->today();

        if ($workingDays <= 0) {
            return $today;
        }

        $date = clone $today;
        $daysToSubtract = $this->getCalendarDaysByWorkDays($workingDays);
        $date->sub(new DateInterval('P' . $daysToSubtract . 'D'));

        return $date;
    }

    /**
     * Finds entries by filter array.
     *
     * @param array<string, mixed> $arFilter
     *
     * @return Entry[]
     */
    public function findByFilterArray(array $arFilter): array
    {
        $queryBuilder = $this->findEntriesWithRelations();

        // Apply filters similar to queryByFilterArray but return results directly
        if (isset($arFilter['customer']) && '' !== $arFilter['customer']) {
            $queryBuilder->andWhere('e.customer = :customer')
                ->setParameter('customer', $arFilter['customer'])
            ;
        }

        if (isset($arFilter['project']) && '' !== $arFilter['project']) {
            $queryBuilder->andWhere('e.project = :project')
                ->setParameter('project', $arFilter['project'])
            ;
        }

        if (isset($arFilter['activity']) && '' !== $arFilter['activity']) {
            $queryBuilder->andWhere('e.activity = :activity')
                ->setParameter('activity', $arFilter['activity'])
            ;
        }

        if (isset($arFilter['user']) && '' !== $arFilter['user']) {
            $queryBuilder->andWhere('e.user = :user')
                ->setParameter('user', $arFilter['user'])
            ;
        }

        if (isset($arFilter['datestart']) && '' !== $arFilter['datestart']) {
            $queryBuilder->andWhere('e.day >= :datestart')
                ->setParameter('datestart', $arFilter['datestart'])
            ;
        }

        if (isset($arFilter['dateend']) && '' !== $arFilter['dateend']) {
            $queryBuilder->andWhere('e.day <= :dateend')
                ->setParameter('dateend', $arFilter['dateend'])
            ;
        }

        // Apply limit if specified with safe casting
        if (isset($arFilter['maxResults']) && is_numeric($arFilter['maxResults'])) {
            $queryBuilder->setMaxResults((int) $arFilter['maxResults']);
        }

        // Apply pagination with safe casting
        if (isset($arFilter['page']) && is_numeric($arFilter['page'])) {
            $page = (int) $arFilter['page'];
            $maxResults = isset($arFilter['maxResults']) && is_numeric($arFilter['maxResults']) ? (int) $arFilter['maxResults'] : 50;
            $offset = $page * $maxResults;
            $queryBuilder->setFirstResult($offset);
        }

        $queryBuilder->orderBy('e.day', 'DESC')
            ->addOrderBy('e.start', 'DESC')
        ;

        $result = $queryBuilder->getQuery()->getResult();

        assert(is_array($result) && array_is_list($result));

        return $result;
    }
}
