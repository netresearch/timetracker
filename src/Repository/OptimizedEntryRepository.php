<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\User;
use App\Enum\Period;
use App\Service\ClockInterface;
use App\Service\TypeSafety\ArrayTypeHelper;
use DateInterval;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Cache\CacheItemPoolInterface;

use function array_key_exists;
use function assert;
use function is_array;
use function is_int;
use function sprintf;

/**
 * Optimized Entry Repository with performance improvements.
 *
 * Key improvements:
 * - Query result caching
 * - Optimized query builders
 * - Reduced N+1 queries through eager loading
 * - Extracted complex query logic into dedicated methods
 *
 * @extends ServiceEntityRepository<Entry>
 */
class OptimizedEntryRepository extends ServiceEntityRepository
{
    private const string CACHE_PREFIX = 'entry_repo_';

    private const int CACHE_TTL = 300;

    public function __construct(
        ManagerRegistry $managerRegistry,
        private readonly ClockInterface $clock,
        private readonly ?CacheItemPoolInterface $cacheItemPool = null,
    ) {
        parent::__construct($managerRegistry, Entry::class);
    }

    /**
     * Returns work log entries for user and recent days with optimized query.
     *
     * @return list<Entry>
     */
    public function findByRecentDaysOfUser(User $user, int $days = 3): array
    {
        $cacheKey = sprintf('%s_recent_%d_%d', self::CACHE_PREFIX, $user->getId(), $days);

        if ($this->cacheItemPool && $cachedResult = $this->getCached($cacheKey)) {
            assert(is_array($cachedResult) && array_is_list($cachedResult));
            /** @var list<Entry> $cachedResult */

            return $cachedResult;
        }

        $fromDate = $this->calculateFromDate($days);

        $queryBuilder = $this->createOptimizedQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.day >= :fromDate')
            ->setParameter('user', $user)
            ->setParameter('fromDate', $fromDate)
            ->orderBy('e.day', 'ASC')
            ->addOrderBy('e.start', 'ASC')
        ;

        $result = $queryBuilder->getQuery()->getResult();

        assert(is_array($result) && array_is_list($result));
        /** @var list<Entry> $result */

        $this->setCached($cacheKey, $result);

        return $result;
    }

    /**
     * Finds entries by date with optimized joins and eager loading.
     *
     * @param array<string, string>|null $arSort
     *
     * @return list<Entry>
     */
    public function findByDate(
        int $userId,
        int $year,
        ?int $month = null,
        ?int $projectId = null,
        ?int $customerId = null,
        ?array $arSort = null,
    ): array {
        $queryBuilder = $this->createOptimizedQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere($this->generateYearExpression('e.day') . ' = :year')
            ->setParameter('user', $userId)
            ->setParameter('year', $year)
        ;

        if (null !== $month) {
            $queryBuilder->andWhere($this->generateMonthExpression('e.day') . ' = :month')
                ->setParameter('month', $month)
            ;
        }

        if (null !== $projectId) {
            $queryBuilder->andWhere('e.project = :project')
                ->setParameter('project', $projectId)
            ;
        }

        if (null !== $customerId) {
            $queryBuilder->andWhere('e.customer = :customer')
                ->setParameter('customer', $customerId)
            ;
        }

        $this->applySort($queryBuilder, $arSort);

        $result = $queryBuilder->getQuery()->getResult();

        assert(is_array($result) && array_is_list($result));
        /** @var list<Entry> $result */

        return $result;
    }

    /**
     * Gets entry summary with optimized single query instead of multiple UNION queries.
     *
     * @return non-empty-array<string, array{scope: string, name: string, entries: int, total: int, own: int, estimation: int}>
     */
    public function getEntrySummaryOptimized(int $entryId, int $userId): array
    {
        $entry = $this->find($entryId);
        if (!$entry instanceof Entry) {
            $emptyData = $this->getEmptySummaryData();
            assert($emptyData !== []);
            return $emptyData;
        }

        $cacheKey = sprintf('%s_summary_%d_%d', self::CACHE_PREFIX, $entryId, $userId);

        if ($this->cacheItemPool && $cachedResult = $this->getCached($cacheKey)) {
            assert(is_array($cachedResult));
            assert(array_key_exists('customer', $cachedResult));
            /** @var non-empty-array<string, array{scope: string, name: string, entries: int, total: int, own: int, estimation: int}> $cachedResult */
            return $cachedResult;
        }

        $connection = $this->getEntityManager()->getConnection();

        // Build database-agnostic project name concatenation
        $projectNameExpr = $this->generateConcatExpression('p.name', "' (Est: '", 'p.estimation', "')'");

        // Use a single query with conditional aggregation instead of UNION
        $sql = "
            SELECT
                -- Customer totals
                COUNT(CASE WHEN e.customer_id = :customerId THEN 1 END) as customer_entries,
                SUM(CASE WHEN e.customer_id = :customerId THEN e.duration END) as customer_total,
                SUM(CASE WHEN e.customer_id = :customerId AND e.user_id = :userId THEN e.duration END) as customer_own,

                -- Project totals
                COUNT(CASE WHEN e.project_id = :projectId THEN 1 END) as project_entries,
                SUM(CASE WHEN e.project_id = :projectId THEN e.duration END) as project_total,
                SUM(CASE WHEN e.project_id = :projectId AND e.user_id = :userId THEN e.duration END) as project_own,

                -- Activity totals
                COUNT(CASE WHEN e.activity_id = :activityId THEN 1 END) as activity_entries,
                SUM(CASE WHEN e.activity_id = :activityId THEN e.duration END) as activity_total,
                SUM(CASE WHEN e.activity_id = :activityId AND e.user_id = :userId THEN e.duration END) as activity_own,

                -- Ticket totals
                COUNT(CASE WHEN e.ticket = :ticket THEN 1 END) as ticket_entries,
                SUM(CASE WHEN e.ticket = :ticket THEN e.duration END) as ticket_total,
                SUM(CASE WHEN e.ticket = :ticket AND e.user_id = :userId THEN e.duration END) as ticket_own,

                -- Get names in subqueries for efficiency
                (SELECT name FROM customers WHERE id = :customerId LIMIT 1) as customer_name,
                (SELECT {$projectNameExpr} FROM projects p WHERE id = :projectId LIMIT 1) as project_name,
                (SELECT name FROM activities WHERE id = :activityId LIMIT 1) as activity_name
            FROM entries e
            WHERE (e.customer_id = :customerId OR e.project_id = :projectId OR e.activity_id = :activityId OR e.ticket = :ticket)
        ";

        $params = [
            'userId' => $userId,
            'customerId' => $entry->getCustomer()?->getId() ?? 0,
            'projectId' => $entry->getProject()?->getId() ?? 0,
            'activityId' => $entry->getActivity()?->getId() ?? 0,
            'ticket' => $entry->getTicket(),
        ];

        $result = $connection->executeQuery($sql, $params)->fetchAssociative();

        $data = $this->formatSummaryData($result ?: null, $entry);
        assert($data !== []);

        $this->setCached($cacheKey, $data);

        return $data;
    }

    /**
     * Gets work by user for period with query optimization.
     *
     * @return array{duration: int, count: int}
     */
    public function getWorkByUserOptimized(int $userId, Period $period = Period::DAY): array
    {
        $cacheKey = sprintf('%s_work_%d_%d', self::CACHE_PREFIX, $userId, $period->value);

        if ($this->cacheItemPool && $cachedResult = $this->getCached($cacheKey)) {
            assert(is_array($cachedResult));
            assert(isset($cachedResult['duration'], $cachedResult['count']));
            assert(is_int($cachedResult['duration']) && is_int($cachedResult['count']));

            return $cachedResult;
        }

        $queryBuilder = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) as count, SUM(e.duration) as duration')
            ->where('e.user = :user')
            ->setParameter('user', $userId)
        ;

        $this->applyPeriodFilter($queryBuilder, $period);

        $result = $queryBuilder->getQuery()->getSingleResult();

        if (!is_array($result)) {
            $result = ['duration' => 0, 'count' => 0];
        }

        // Ensure result is properly typed as array<string, mixed>
        /** @var array<string, mixed> $typedResult */
        $typedResult = $result;
        
        $data = [
            'duration' => ArrayTypeHelper::getInt($typedResult, 'duration', 0) ?? 0,
            'count' => ArrayTypeHelper::getInt($typedResult, 'count', 0) ?? 0,
        ];

        $this->setCached($cacheKey, $data, 60); // Shorter cache for work stats

        return $data;
    }

    /**
     * Finds entries with optimized query using indexes.
     *
     * @param array<string, mixed> $filter
     *
     * @return list<Entry>
     */
    public function findByFilterArrayOptimized(array $filter = []): array
    {
        $queryBuilder = $this->createOptimizedQueryBuilder('e');

        // Apply filters with index-aware ordering
        if (isset($filter['user_id'])) {
            $queryBuilder->andWhere('e.user = :user')
                ->setParameter('user', $filter['user_id'])
            ;
        }

        if (isset($filter['customer_id'])) {
            $queryBuilder->andWhere('e.customer = :customer')
                ->setParameter('customer', $filter['customer_id'])
            ;
        }

        if (isset($filter['project_id'])) {
            $queryBuilder->andWhere('e.project = :project')
                ->setParameter('project', $filter['project_id'])
            ;
        }

        if (isset($filter['activity_id'])) {
            $queryBuilder->andWhere('e.activity = :activity')
                ->setParameter('activity', $filter['activity_id'])
            ;
        }

        if (isset($filter['date_from'])) {
            $queryBuilder->andWhere('e.day >= :date_from')
                ->setParameter('date_from', $filter['date_from'])
            ;
        }

        if (isset($filter['date_to'])) {
            $queryBuilder->andWhere('e.day <= :date_to')
                ->setParameter('date_to', $filter['date_to'])
            ;
        }

        if (isset($filter['ticket'])) {
            $queryBuilder->andWhere('e.ticket = :ticket')
                ->setParameter('ticket', $filter['ticket'])
            ;
        }

        // Optimize sorting for indexed columns
        $queryBuilder->orderBy('e.day', 'DESC')
            ->addOrderBy('e.start', 'DESC')
        ;

        // Add limit if specified
        if (isset($filter['limit'])) {
            /** @var array<string, mixed> $typedFilter */
            $typedFilter = $filter;
            $queryBuilder->setMaxResults(ArrayTypeHelper::getInt($typedFilter, 'limit', 100));
        }

        $result = $queryBuilder->getQuery()->getResult();

        assert(is_array($result) && array_is_list($result));
        /** @var list<Entry> $result */

        return $result;
    }

    /**
     * Creates optimized query builder with eager loading.
     */
    private function createOptimizedQueryBuilder(string $alias): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->select($alias, 'u', 'c', 'p', 'a')
            ->leftJoin($alias . '.user', 'u')
            ->leftJoin($alias . '.customer', 'c')
            ->leftJoin($alias . '.project', 'p')
            ->leftJoin($alias . '.activity', 'a')
        ;
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
                    ->setParameter('today', $today)
                ;
                break;

            case Period::WEEK:
                $startOfWeek = clone $today;
                $startOfWeek = $startOfWeek->modify('monday this week') ?: $startOfWeek;
                $endOfWeek = clone $startOfWeek;
                $endOfWeek = $endOfWeek->modify('+6 days') ?: $endOfWeek;

                $queryBuilder->andWhere('e.day BETWEEN :start AND :end')
                    ->setParameter('start', $startOfWeek)
                    ->setParameter('end', $endOfWeek)
                ;
                break;

            case Period::MONTH:
                $queryBuilder->andWhere($this->generateYearExpression('e.day') . ' = :year')
                    ->andWhere($this->generateMonthExpression('e.day') . ' = :month')
                    ->setParameter('year', $today->format('Y'))
                    ->setParameter('month', $today->format('m'))
                ;
                break;
        }
    }

    /**
     * Applies sorting to query builder.
     *
     * @param array<string, string>|null $sort
     */
    private function applySort(QueryBuilder $queryBuilder, ?array $sort): void
    {
        if (null === $sort || [] === $sort) {
            $queryBuilder->orderBy('e.day', 'DESC')
                ->addOrderBy('e.start', 'DESC')
            ;

            return;
        }

        foreach ($sort as $field => $direction) {
            $direction = 'ASC' === strtoupper($direction) ? 'ASC' : 'DESC';
            $queryBuilder->addOrderBy('e.' . $field, $direction);
        }
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
     * Converts working days to calendar days.
     */
    public function getCalendarDaysByWorkDays(int $workingDays): int
    {
        if ($workingDays <= 0) {
            return 0;
        }

        $days = 0;
        $date = clone $this->clock->today();

        while ($workingDays > 0) {
            ++$days;
            $date->sub(new DateInterval('P1D'));
            $dayOfWeek = (int) $date->format('N');

            if ($dayOfWeek < 6) { // Monday to Friday
                --$workingDays;
            }
        }

        return $days;
    }

    /**
     * Formats summary data from query result.
     *
     * @param array<string, mixed>|null $result
     *
     * @return non-empty-array<string, array{scope: string, name: string, entries: int, total: int, own: int, estimation: int}>
     */
    private function formatSummaryData(?array $result, Entry $entry): array
    {
        if ($result === null) {
            return $this->getEmptySummaryData();
        }

        $project = $entry->getProject();

        return [
            'customer' => [
                'scope' => 'customer',
                'name' => ArrayTypeHelper::getString($result, 'customer_name', '') ?? '',
                'entries' => ArrayTypeHelper::getInt($result, 'customer_entries', 0) ?? 0,
                'total' => ArrayTypeHelper::getInt($result, 'customer_total', 0) ?? 0,
                'own' => ArrayTypeHelper::getInt($result, 'customer_own', 0) ?? 0,
                'estimation' => 0,
            ],
            'project' => [
                'scope' => 'project',
                'name' => ArrayTypeHelper::getString($result, 'project_name', '') ?? '',
                'entries' => ArrayTypeHelper::getInt($result, 'project_entries', 0) ?? 0,
                'total' => ArrayTypeHelper::getInt($result, 'project_total', 0) ?? 0,
                'own' => ArrayTypeHelper::getInt($result, 'project_own', 0) ?? 0,
                'estimation' => $project?->getEstimation() ?? 0,
            ],
            'activity' => [
                'scope' => 'activity',
                'name' => ArrayTypeHelper::getString($result, 'activity_name', '') ?? '',
                'entries' => ArrayTypeHelper::getInt($result, 'activity_entries', 0) ?? 0,
                'total' => ArrayTypeHelper::getInt($result, 'activity_total', 0) ?? 0,
                'own' => ArrayTypeHelper::getInt($result, 'activity_own', 0) ?? 0,
                'estimation' => 0,
            ],
            'ticket' => [
                'scope' => 'ticket',
                'name' => $entry->getTicket() ?: '',
                'entries' => ArrayTypeHelper::getInt($result, 'ticket_entries', 0) ?? 0,
                'total' => ArrayTypeHelper::getInt($result, 'ticket_total', 0) ?? 0,
                'own' => ArrayTypeHelper::getInt($result, 'ticket_own', 0) ?? 0,
                'estimation' => 0,
            ],
        ];
    }

    /**
     * Returns empty summary data structure.
     *
     * @return non-empty-array<string, array{scope: string, name: string, entries: int, total: int, own: int, estimation: int}>
     */
    private function getEmptySummaryData(): array
    {
        $empty = ['scope' => '', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0];

        return [
            'customer' => array_merge($empty, ['scope' => 'customer']),
            'project' => array_merge($empty, ['scope' => 'project']),
            'activity' => array_merge($empty, ['scope' => 'activity']),
            'ticket' => array_merge($empty, ['scope' => 'ticket']),
        ];
    }

    /**
     * Generate database-agnostic YEAR expression.
     */
    private function generateYearExpression(string $field): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
            return sprintf('YEAR(%s)', $field);
        }

        return sprintf("strftime('%%Y', %s)", $field);
    }

    /**
     * Generate database-agnostic MONTH expression.
     */
    private function generateMonthExpression(string $field): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
            return sprintf('MONTH(%s)', $field);
        }

        return sprintf("strftime('%%m', %s)", $field);
    }

    /**
     * Generate database-agnostic CONCAT expression.
     */
    private function generateConcatExpression(string ...$fields): string
    {
        $platform = $this->getEntityManager()->getConnection()->getDatabasePlatform();

        if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
            return 'CONCAT(' . implode(', ', $fields) . ')';
        }

        // SQLite: use || operator
        return '(' . implode(' || ', $fields) . ')';
    }

    /**
     * Gets cached result if available.
     */
    private function getCached(string $key): mixed
    {
        if (!$this->cacheItemPool) {
            return null;
        }

        $item = $this->cacheItemPool->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * Sets cached result.
     */
    private function setCached(string $key, mixed $data, int $ttl = self::CACHE_TTL): void
    {
        if (!$this->cacheItemPool) {
            return;
        }

        $item = $this->cacheItemPool->getItem($key);
        $item->set($data);
        $item->expiresAfter($ttl);

        $this->cacheItemPool->save($item);
    }
}
