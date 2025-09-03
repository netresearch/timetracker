<?php

declare(strict_types=1);

/**
 * Netresearch Timetracker.
 *
 * PHP version 5
 *
 * @category   Netresearch
 *
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */

namespace App\Repository;

use App\Dto\DatabaseResultDto;
use App\Entity\Entry;
use App\Entity\User;
use App\Enum\Period;
use App\Service\ClockInterface;
use App\Service\TypeSafety\ArrayTypeHelper;
use App\Service\Util\TimeCalculationService;
use DateInterval;
use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Persistence\ManagerRegistry;
use Exception;

use function sprintf;

/**
 * Class EntryRepository.
 *
 * @category   Netresearch
 *
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 *
 * @see       http://www.netresearch.de
 */
/**
 * @extends ServiceEntityRepository<Entry>
 */
class EntryRepository extends ServiceEntityRepository
{
    /**
     * Convert N working days into calendar days by skipping weekends.
     */
    public function getCalendarDaysByWorkDays(int $workingDays): int
    {
        if ($workingDays <= 0) {
            return 0;
        }

        $days = 0;
        $date = $this->clock->today();
        while ($workingDays > 0) {
            ++$days;
            $date = $date->sub(new DateInterval('P1D'));
            $dayOfWeek = (int) $date->format('N'); // 1 (Mon) .. 7 (Sun)
            if ($dayOfWeek < 6) {
                --$workingDays;
            }
        }

        return $days;
    }

    public function __construct(ManagerRegistry $managerRegistry, private readonly ClockInterface $clock, private readonly TimeCalculationService $timeCalculationService)
    {
        parent::__construct($managerRegistry, Entry::class);
    }


    /**
     * Returns work log entries for user and recent days.
     * Uses DQL.
     *
     * @throws Exception
     *
     * @return array<int, Entry>
     */
    public function findByRecentDaysOfUser(User $user, int $days = 3): array
    {
        $today = $this->clock->today();
        $calendarDays = $this->getCalendarDaysByWorkDays($days);

        $fromDate = $calendarDays <= 0 ? $today : $today->sub(new DateInterval('P' . $calendarDays . 'D'));

        $entityManager = $this->getEntityManager();
        $query = $entityManager->createQuery(
            'SELECT e FROM App\Entity\Entry e'
            . ' WHERE e.user = :user_id AND e.day >= :fromDate'
            . ' ORDER BY e.day, e.start ASC',
        )->setParameter('user_id', $user->getId(), \Doctrine\DBAL\ParameterType::INTEGER)
            ->setParameter('fromDate', $fromDate)
        ;

        /** @var Entry[] $result */
        $result = $query->getResult();
        
        return $result;
    }

    /**
     * get all entries of a user in a given year and month.
     *
     * @param int                      $userId     Filter entries by user
     * @param int                      $year       Filter entries by year
     * @param int                      $month      Filter entries by month
     * @param int                      $projectId  Filter entries by project
     * @param int                      $customerId Filter entries by customer
     * @param array<string, bool>|null $arSort
     *
     * @return array<int, Entry>
     */
    public function findByDate(int $userId, int $year, ?int $month = null, ?int $projectId = null, ?int $customerId = null, ?array $arSort = null): array
    {
        if (null === $arSort) {
            $arSort = [
                'entry.day' => true,
                'entry.start' => true,
            ];
        }

        $queryBuilder = $this->getEntityManager()->createQueryBuilder();

        $queryBuilder->select('entry')
            ->from(Entry::class, 'entry')
            ->leftJoin('entry.user', 'user')
        ;

        foreach ($arSort as $strField => $bAsc) {
            $queryBuilder->addOrderBy($strField, $bAsc ? 'ASC' : 'DESC');
        }

        if (0 < $userId) {
            $queryBuilder->andWhere('entry.user = :user_id');
            $queryBuilder->setParameter('user_id', $userId, \Doctrine\DBAL\ParameterType::INTEGER);
        }

        if (0 < (int) $projectId) {
            $queryBuilder->andWhere('entry.project = :project_id');
            $queryBuilder->setParameter('project_id', $projectId, \Doctrine\DBAL\ParameterType::INTEGER);
        }

        if (0 < (int) $customerId) {
            $queryBuilder->andWhere('entry.customer = :customer_id');
            $queryBuilder->setParameter('customer_id', $customerId, \Doctrine\DBAL\ParameterType::INTEGER);
        }

        if (0 < $year) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like('entry.day', ':month'),
            );
            $queryBuilder->setParameter('month', sprintf('%04d-%02d-%%', $year, $month ?? 0), \Doctrine\DBAL\ParameterType::STRING);
        }

        /** @var Entry[] $result */
        $result = $queryBuilder->getQuery()->getResult();
        
        // Doctrine guarantees Entry[] when querying Entry repository
        return $result;
    }

    /**
     * get all entries of a user on a specific day.
     *
     * @param int    $userId Filter by user ID
     * @param string $day    Filter by date
     *
     * @return array<int, Entry>
     */
    public function findByDay(int $userId, string $day): array
    {
        $entityManager = $this->getEntityManager();

        $query = $entityManager->createQuery(
            'SELECT e FROM App\Entity\Entry e'
            . ' WHERE e.user = :user_id'
            . ' AND e.day = :day'
            . ' ORDER BY e.start ASC, e.end ASC, e.id ASC',
        )->setParameter('user_id', $userId, \Doctrine\DBAL\ParameterType::INTEGER)
            ->setParameter('day', $day)
        ;

        /** @var Entry[] $result */
        $result = $query->getResult();
        
        return $result;
    }

    /**
     * Returns work log entries for a specific user within a time range.
     * Uses raw SQL for performance/legacy reasons? Refactored to use prepared statements.
     *
     * This method retrieves entries based on working days rather than calendar days.
     * It converts the requested number of working days to calendar days using getCalendarDaysByWorkDays().
     *
     * @throws \Doctrine\DBAL\Exception
     *
     * @return array<int, array{entry: array<string, mixed>}>
     */
    public function getEntriesByUser(int $userId, int $days = 3, bool $showFuture = true): array
    {
        $today = $this->clock->today();
        $calendarDays = $this->getCalendarDaysByWorkDays($days);

        $connection = $this->getEntityManager()->getConnection();
        $params = [
            'userId' => $userId,
            // Format date for SQL parameter binding
            'fromDate' => $today->sub(new DateInterval('P' . $calendarDays . 'D'))->format('Y-m-d'),
        ];

        $sql = [];
        $sql['select'] = "SELECT e.id,
        	DATE_FORMAT(e.day, '%d/%m/%Y') AS `date`,
        	DATE_FORMAT(e.start,'%H:%i') AS `start`,
         	DATE_FORMAT(e.end,'%H:%i') AS `end`,
        	e.user_id AS user,
        	e.customer_id AS customer,
        	e.project_id AS project,
        	e.activity_id AS activity,
        	e.description,
            e.ticket,
            e.class,
            e.duration,
            e.internal_jira_ticket_original_key as extTicket,
            REPLACE(t.ticketurl,'%s',e.internal_jira_ticket_original_key) as extTicketUrl";
        $sql['from'] = 'FROM entries e LEFT JOIN projects p ON e.project_id = p.id LEFT JOIN ticket_systems t ON p.ticket_system = t.id';
        // Modified: Use parameter binding for start date
        $sql['where_day'] = 'WHERE day >= :fromDate';

        if (!$showFuture) {
            // Modified: Use parameter binding for today's date
            $sql['where_future'] = 'AND day <= :today';
            $params['today'] = $today->format('Y-m-d');
        }

        // Modified: Use parameter binding for user ID
        $sql['where_user'] = 'AND user_id = :userId';
        $sql['order'] = 'ORDER BY day DESC, start DESC';

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
            $data[] = ['entry' => $transformedLine];
        }

        return $data;
    }

    /**
     * Get array of entries of given user and ticketsystem which should be synced to the ticketsystem.
     * Ordered by date, starttime desc.
     *
     * @param int $maxResults (optional) max number of results to be returned
     *                        if null: no result limitation
     *
     * @return Entry[]
     */
    /**
     * @return array<int, Entry>
     */
    public function findByUserAndTicketSystemToSync(int $userId, int $ticketSystemId, ?int $maxResults = null): array
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder();
        $queryBuilder
            ->select('e')
            ->from(Entry::class, 'e')
            ->join(\App\Entity\Project::class, 'p', Join::WITH, 'e.project = p.id')
            ->where('e.user = :user_id')
            ->andWhere('e.syncedToTicketsystem = false')
            ->andWhere('p.ticketSystem = :ticket_system_id')
            ->setParameter('user_id', $userId)
            ->setParameter('ticket_system_id', $ticketSystemId)
            ->orderBy('e.day', 'DESC')
            ->addOrderBy('e.start', 'DESC')
        ;

        if ((int) $maxResults > 0) {
            $queryBuilder->setMaxResults((int) $maxResults);
        }

        /** @var Entry[] $result */
        $result = $queryBuilder->getQuery()->getResult();
        
        // Doctrine guarantees Entry[] when querying Entry repository
        return $result;
    }

    /**
     * Query summary information regarding the current entry for the following
     * scopes: customer, project, activity, ticket.
     *
     * @param array<string, array{scope:string,name:string,entries:int,total:int,own:int,estimation:int}> $data
     *
     * @throws \Doctrine\DBAL\Exception
     *
     * @return array<string, array{scope:string,name:string,entries:int,total:int,own:int,estimation:int}>
     */
    public function getEntrySummary(int $entryId, int $userId, array $data): array
    {
        $entry = $this->find($entryId);
        if (!$entry instanceof Entry) {
            return $data;
        }

        $connection = $this->getEntityManager()->getConnection();

        $sql = ['customer' => [], 'project' => [], 'ticket' => []];

        // customer total / customer total by current user
        $sql['customer']['select'] = "SELECT 'customer' AS scope,
            c.name AS name,
            COUNT(e.id) AS entries,
            SUM(e.duration) AS total,
            SUM(IF(e.user_id = :userId , e.duration, 0)) AS own,
            0 as estimation";
        $sql['customer']['from'] = 'FROM entries e';
        $sql['customer']['join_c'] = 'LEFT JOIN customers c ON c.id = e.customer_id';
        if ($entry->getCustomer() instanceof \App\Entity\Customer) {
            $sql['customer']['where_c'] = 'WHERE e.customer_id = :customerId';
        } else {
            $sql['customer']['where_c'] = '';
        }

        // project total / project total by current user
        $sql['project']['select'] = "SELECT 'project' AS scope,
            CONCAT(p.name) AS name,
            COUNT(e.id) AS entries,
            SUM(e.duration) AS total,
            SUM(IF(e.user_id = :userId , e.duration, 0)) AS own,
            p.estimation AS estimation";
        $sql['project']['from'] = 'FROM entries e';
        $sql['project']['join_c'] = 'LEFT JOIN customers c ON c.id = e.customer_id';
        $sql['project']['join_p'] = 'LEFT JOIN projects p ON p.id=e.project_id';
        $sql['project']['where_c'] = $entry->getCustomer() instanceof \App\Entity\Customer ? 'WHERE e.customer_id = :customerId' : '';
        $sql['project']['where_p'] = $entry->getProject() instanceof \App\Entity\Project ? 'AND e.project_id = :projectId' : '';

        // activity total / activity total by current user
        if ($entry->getActivity() instanceof \App\Entity\Activity) {
            $sql['activity']['select'] = "SELECT 'activity' AS scope,
                CONCAT(a.name) AS name,
                COUNT(e.id) AS entries,
                SUM(e.duration) AS total,
                SUM(IF(e.user_id = :userId , e.duration, 0)) AS own,
                0 as estimation";
            $sql['activity']['from'] = 'FROM entries e';
            $sql['activity']['join_c'] = 'LEFT JOIN customers c ON c.id = e.customer_id';
            $sql['activity']['join_p'] = 'LEFT JOIN projects p ON p.id=e.project_id';
            $sql['activity']['join_a'] = 'LEFT JOIN activities a ON a.id=e.activity_id';
            $sql['activity']['where_c'] = $entry->getCustomer() instanceof \App\Entity\Customer ? 'WHERE e.customer_id = :customerId' : '';
            $sql['activity']['where_p'] = $entry->getProject() instanceof \App\Entity\Project ? 'AND e.project_id = :projectId' : '';
            $sql['activity']['where_a'] = 'AND e.activity_id = :activityId';
        } else {
            $sql['activity']['select'] = "SELECT 'activity' AS scope, '' AS name, 0 as entries, 0 as total, 0 as own";
        }

        if ('' !== $entry->getTicket()) {
            // ticket total / ticket total by current user
            $sql['ticket']['select'] = "SELECT 'ticket' AS scope,
                ticket AS name,
                COUNT(id) AS entries,
                SUM(duration) AS total,
                SUM(IF(user_id = :userId, duration, 0)) AS own,
                0 as estimation";
            $sql['ticket']['from'] = 'FROM entries';
            $sql['ticket']['where'] = 'WHERE ticket = :ticketName';
        } else {
            $sql['ticket']['select'] = "SELECT 'ticket' AS scope, '' AS name, 0 as entries, 0 as total, 0 as own, 0 AS estimation";
        }

        // Prepare parameters for binding
        $params = ['userId' => $userId];

        if ($entry->getCustomer() instanceof \App\Entity\Customer) {
            $params['customerId'] = $entry->getCustomer()->getId();
        }
        if ($entry->getProject() instanceof \App\Entity\Project) {
            $params['projectId'] = $entry->getProject()->getId();
        }
        if ($entry->getActivity() instanceof \App\Entity\Activity) {
            $params['activityId'] = $entry->getActivity()->getId();
        }
        if ('' !== $entry->getTicket()) {
            $params['ticketName'] = $entry->getTicket();
        }

        // Build and execute the UNION query with prepared statements
        $fullQuery = implode(' ', $sql['customer'])
            . ' UNION ' . implode(' ', $sql['project'])
            . ' UNION ' . implode(' ', $sql['activity'])
            . ' UNION ' . implode(' ', $sql['ticket']);

        $statement = $connection->prepare($fullQuery);
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $result = $statement->executeQuery()->fetchAllAssociative();

        // ensure consistent array shapes using DTO transformations
        $data['customer'] = isset($result[0]) 
            ? DatabaseResultDto::transformScopeRow($result[0], 'customer')
            : ['scope' => 'customer', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0];
        $data['project'] = isset($result[1]) 
            ? DatabaseResultDto::transformScopeRow($result[1], 'project')
            : ['scope' => 'project', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0];
        $data['activity'] = isset($result[2]) 
            ? DatabaseResultDto::transformScopeRow($result[2], 'activity')
            : ['scope' => 'activity', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0];
        $data['ticket'] = isset($result[3]) 
            ? DatabaseResultDto::transformScopeRow($result[3], 'ticket')
            : ['scope' => 'ticket', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0];

        return $data;
    }

    /**
     * Query the current user's work by given period.
     * Uses raw SQL. Refactored to use prepared statements.
     *
     * @throws \Doctrine\DBAL\Exception
     *
     * @return array{duration: int|mixed, count: bool}
     */
    public function getWorkByUser(int $userId, Period $period = Period::DAY): array
    {
        $today = $this->clock->today();
        $connection = $this->getEntityManager()->getConnection();
        $params = ['userId' => $userId];

        $sql = [];
        $sql['select'] = 'SELECT COUNT(id) AS count, SUM(duration) AS duration';
        $sql['from'] = 'FROM entries';
        // Modified: Use parameter binding for user ID
        $sql['where_user'] = 'WHERE user_id = :userId';

        switch ($period) {
            case Period::DAY:
                // Modified: Use parameter binding for today's date
                $sql['where_day'] = 'AND day = :todayDate';
                $params['todayDate'] = $today->format('Y-m-d');
                break;
            case Period::WEEK:
                // Modified: Use parameter binding for year and week
                $sql['where_year'] = 'AND YEAR(day) = :year';
                // Assuming WEEK(day, 1) aligns with ISO-8601 week (starts Monday) like PHP 'W'
                $sql['where_week'] = 'AND WEEK(day, 1) = :week';
                $params['year'] = $today->format('Y');
                $params['week'] = $today->format('W');
                break;
            case Period::MONTH:
                // Modified: Use parameter binding for year and month
                $sql['where_year'] = 'AND YEAR(day) = :year';
                $sql['where_month'] = 'AND MONTH(day) = :month';
                $params['year'] = $today->format('Y');
                $params['month'] = $today->format('m');
                break;
        }

        // Modified: Use prepare and executeQuery with parameters
        $statement = $connection->prepare(implode(' ', $sql));
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $result = $statement->executeQuery()->fetchAllAssociative(); // Use fetchAllAssociative for DBAL 3+

        // Original code returned false for count, keeping that behavior
        return [
            'duration' => $result[0]['duration'] ?? 0, // Handle potential empty result
            'count' => false,
        ];
    }

    /**
     * Get query of entries for given filter params.
     *
     *  $arFilter[customer]         => int customer_id
     *           [project]          => int project_id
     *           [user]             => int user_id
     *           [activity]         => int activity_id
     *           [team]             => int team_id
     *           [datestart]        => string
     *           [dateend]          => string
     *           [ticket]           => string
     *           [description]      => string
     *           [maxResults]       => int max number of returned datasets
     *           [visibility_user]  => user_id restricts entry visibility by users teams
     *
     * @param array<string, mixed> $arFilter
     *
     * @throws Exception
     */
    /**
     * @param array<string, mixed> $arFilter
     *
     * @return \Doctrine\ORM\Query<int, Entry>
     */
    public function queryByFilterArray(array $arFilter = []): \Doctrine\ORM\Query
    {
        $queryBuilder = $this->createQueryBuilder('e');

        $customerId = ArrayTypeHelper::getInt($arFilter, 'customer');
        if ($customerId !== null) {
            $queryBuilder
                ->andWhere('e.customer = :customer')
                ->setParameter('customer', $customerId)
            ;
        }

        $projectId = ArrayTypeHelper::getInt($arFilter, 'project');
        if ($projectId !== null) {
            $queryBuilder
                ->andWhere('e.project = :project')
                ->setParameter('project', $projectId)
            ;
        }

        $userId = ArrayTypeHelper::getInt($arFilter, 'user');
        if ($userId !== null) {
            $queryBuilder
                ->andWhere('e.user = :user')
                ->setParameter('user', $userId)
            ;
        }

        $teamId = ArrayTypeHelper::getInt($arFilter, 'teams');
        if ($teamId !== null) {
            $queryBuilder
                ->join('e.user', 'u')
                ->join('u.teams', 't')
                ->andWhere('t.id = :team')
                ->setParameter('team', $teamId)
            ;
        }

        $dateStart = ArrayTypeHelper::getString($arFilter, 'datestart');
        if ($dateStart !== null) {
            $date = new DateTime($dateStart);
            $queryBuilder->andWhere('e.day >= :start')
                ->setParameter('start', $date->format('Y-m-d'))
            ;
        }

        $dateEnd = ArrayTypeHelper::getString($arFilter, 'dateend');
        if ($dateEnd !== null) {
            $date = new DateTime($dateEnd);
            $queryBuilder->andWhere('e.day <= :end')
                ->setParameter('end', $date->format('Y-m-d'))
            ;
        }

        $activityId = ArrayTypeHelper::getInt($arFilter, 'activity');
        if ($activityId !== null) {
            $queryBuilder
                ->andWhere('e.activity = :activity')
                ->setParameter('activity', $activityId)
            ;
        }

        $ticket = ArrayTypeHelper::getString($arFilter, 'ticket');
        if ($ticket !== null) {
            $queryBuilder
                ->andWhere('e.ticket LIKE :ticket')
                ->setParameter('ticket', $ticket)
            ;
        }

        $description = ArrayTypeHelper::getString($arFilter, 'description');
        if ($description !== null) {
            $queryBuilder
                ->andWhere('e.description LIKE :description')
                ->setParameter('description', '%' . $description . '%')
            ;
        }

        $maxResults = ArrayTypeHelper::getInt($arFilter, 'maxResults');
        if ($maxResults !== null && $maxResults > 0) {
            $queryBuilder
                ->orderBy('e.id', 'DESC')
                ->setMaxResults($maxResults)
            ;
        }

        // pagination offset
        $page = ArrayTypeHelper::getInt($arFilter, 'page');
        $maxResultsForPagination = ArrayTypeHelper::getInt($arFilter, 'maxResults');
        if ($page !== null && $maxResultsForPagination !== null) {
            $queryBuilder
                ->setFirstResult($page * $maxResultsForPagination)
            ;
        }

        $visibilityUser = ArrayTypeHelper::getInt($arFilter, 'visibility_user');
        if ($visibilityUser !== null) {
            $queryBuilder
                ->andWhere('e.user = :vis_user')
                ->setParameter('vis_user', $visibilityUser)
            ;
        }

        /** @var \Doctrine\ORM\Query<int, Entry> */
        return $queryBuilder->getQuery();
    }

    /**
     * Get array of entries for given filter params.
     *
     * @param array $arFilter every value is optional
     *
     *  $arFilter[customer]         => int customer_id
     *           [project]          => int project_id
     *           [user]             => int user_id
     *           [activity]         => int activity_id
     *           [team]             => int team_id
     *           [datestart]        => string
     *           [dateend]          => string
     *           [ticket]           => string
     *           [description]      => string
     *           [maxResults]       => int max number of returned datasets
     *           [visibility_user]  => user_id restricts entry visibility by users teams
     *
     * @throws Exception
     *
     * @return array<int, Entry>
     */
    /**
     * @param array<string, mixed> $arFilter
     *
     * @return array<int, Entry>
     */
    public function findByFilterArray(array $arFilter = []): array
    {
        return $this->queryByFilterArray($arFilter)->getResult();
    }

    /**
     * Get a list of activities with the total time booked on the ticket.
     *
     * @return array<int, array{name: string|null, total_time: int|string|null}>
     */
    public function getActivitiesWithTime(string $ticketname): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = 'SELECT name, SUM(duration) AS total_time
                FROM entries
                LEFT JOIN  activities
                ON entries.activity_id = activities.id
                WHERE entries.ticket = :ticketname
                GROUP BY activity_id';

        $statement = $connection->prepare($sql);
        $statement->bindValue(':ticketname', $ticketname);

        /** @var array<int, array{name: string|null, total_time: int|string|null}> $rows */
        $rows = $statement->executeQuery()->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'name' => $row['name'] ?? null,
                'total_time' => isset($row['total_time']) ? (int) $row['total_time'] : null,
            ],
            $rows,
        );
    }

    /**
     * Get a list of usernames that worked on the ticket and the total time they spent on it.
     *
     * @return array<int, array{username: string, total_time: int|string|null}>
     */
    public function getUsersWithTime(string $ticketname): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = 'SELECT username, SUM(duration) AS total_time
                FROM users, entries
                WHERE entries.ticket = :ticketname
                AND users.id = entries.user_id
                GROUP BY username';

        $statement = $connection->prepare($sql);
        $statement->bindValue(':ticketname', $ticketname);

        /** @var array<int, array{username: string, total_time: int|string|null}> $rows */
        $rows = $statement->executeQuery()->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'username' => $row['username'],
                'total_time' => isset($row['total_time']) ? (int) $row['total_time'] : null,
            ],
            $rows,
        );
    }
}
