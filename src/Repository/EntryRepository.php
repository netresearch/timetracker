<?php

/**
 * Netresearch Timetracker
 *
 * PHP version 5
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Repository
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */

namespace App\Repository;

use Doctrine\ORM\Query\Expr\Join;
use App\Entity\Entry;
use App\Entity\User;
use App\Helper\TimeHelper;
use App\Service\ClockInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class EntryRepository
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Repository
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */
class EntryRepository extends ServiceEntityRepository
{
    public const PERIOD_DAY   = 1;

    public const PERIOD_WEEK  = 2;

    public const PERIOD_MONTH = 3;

    private ClockInterface $clock;

    /**
     * EntryRepository constructor.
     */
    public function __construct(ManagerRegistry $managerRegistry, ClockInterface $clock)
    {
        parent::__construct($managerRegistry, Entry::class);
        $this->clock = $clock;
    }

    /**
     * Converts a number of working days to the equivalent number of calendar days.
     *
     * This method calculates how many calendar days are needed to cover the specified
     * number of working days, taking into account weekends and the current day of the week.
     *
     * The calculation logic:
     * 1. First handles full weeks (5 working days = 7 calendar days)
     * 2. Then handles remaining days, accounting for weekends
     * 3. Adjusts based on the current day of the week (determined by the injected clock)
     *    to ensure correct counting
     *
     * For example:
     * - 1 working day on Tuesday means 1 calendar day (just Tuesday)
     * - 1 working day on Monday means 3 calendar days (includes Friday from previous week)
     * - 5 working days means 7 calendar days (a full week)
     * - 6 working days means 8 calendar days (a full week plus one day)
     */
    public function getCalendarDaysByWorkDays(int $workingDays): int
    {
        if ($workingDays < 1) {
            return 0;
        }

        // Calculate calendar days from given work days
        $weeks = floor($workingDays / 5);
        $restDays = ($workingDays) % 5;

        if ($restDays == 0) {
            // No remaining days, just return full weeks * 7
            return (int) ($weeks * 7);
        }

        // Get day of week from clock (0=Sun, 1=Mon, ..., 6=Sat)
        $dayOfWeek = (int) $this->clock->today()->format("w");

        // Adjust restDays based on the current day of the week
        switch ($dayOfWeek) {
            case 6: // Saturday
                $restDays++; // Need to account for Saturday itself if counting back
                break;
            case 0: // Sunday (was 7 in original date('w') but format('w') is 0)
                $restDays += 2; // Need to account for Sunday and Saturday
                break;
            default: // Monday to Friday
                // If the span of restDays crosses the *previous* weekend when counting back from today
                // (e.g., today is Tuesday (2) and restDays is 2, it includes Mon, Sun, Sat)
                if ($dayOfWeek <= $restDays) { // Check if dayOfWeek index (0-6) is less than or equal to remaining days (1-4)
                    $restDays += 2; // Add Saturday and Sunday
                }
                break;
        }

        return (int) (($weeks * 7) + $restDays);
    }


    /**
     * Returns work log entries for user and recent days.
     * Uses DQL.
     *
     * @throws \Exception
     */
    public function findByRecentDaysOfUser(User $user, int $days = 3): array
    {
        $today = $this->clock->today();
        $calendarDays = $this->getCalendarDaysByWorkDays($days);

        if ($calendarDays <= 0) {
            // Avoid creating invalid DateInterval P0D
            $fromDate = $today;
        } else {
            $fromDate = $today->sub(new \DateInterval('P' . $calendarDays . 'D'));
        }


        $entityManager = $this->getEntityManager();
        $query = $entityManager->createQuery(
            'SELECT e FROM App\Entity\Entry e'
            . ' WHERE e.user = :user_id AND e.day >= :fromDate'
            . ' ORDER BY e.day, e.start ASC'
        )->setParameter('user_id', $user->getId())
         ->setParameter('fromDate', $fromDate);

        return $query->getResult();
    }



    /**
     * get all entries of a user in a given year and month
     *
     * @param integer $userId     Filter entries by user
     * @param integer $year       Filter entries by year
     * @param integer $month      Filter entries by month
     * @param integer $projectId  Filter entries by project
     * @param integer $customerId Filter entries by customer
     * @param array   $arSort     Sort result by given fields
     *
     * @return \App\Entity\Entry[]
     */
    public function findByDate(int $userId, int $year, ?int $month = null, ?int $projectId = null, ?int $customerId = null, ?array $arSort = null): array
    {
        if (null === $arSort) {
            $arSort = [
                'entry.day'   => true,
                'entry.start' => true,
            ];
        }

        $queryBuilder = $this->getEntityManager()->createQueryBuilder();

        $queryBuilder->select('entry')
            ->from(\App\Entity\Entry::class, 'entry')
            ->leftJoin('entry.user', 'user');

        foreach ($arSort as $strField => $bAsc) {
            $queryBuilder->addOrderBy($strField, $bAsc ? 'ASC' : 'DESC');
        }


        if (0 < $userId) {
            $queryBuilder->andWhere('entry.user = :user_id');
            $queryBuilder->setParameter('user_id', $userId, \PDO::PARAM_INT);
        }

        if (0 < (int) $projectId) {
            $queryBuilder->andWhere('entry.project = :project_id');
            $queryBuilder->setParameter('project_id', $projectId, \PDO::PARAM_INT);
        }

        if (0 < (int) $customerId) {
            $queryBuilder->andWhere('entry.customer = :customer_id');
            $queryBuilder->setParameter('customer_id', $customerId, \PDO::PARAM_INT);
        }

        if (0 < $year) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like('entry.day', ':month')
            );
            $queryBuilder->setParameter('month', $this->getDatePattern($year, $month), \PDO::PARAM_STR);
        }

        return $queryBuilder->getQuery()->getResult();
    }



    /**
     * Returns the date pattern for the repository queries according to year
     * and month
     */
    protected function getDatePattern(int $year, ?int $month = null): string
    {
        $pattern = $year . '-';
        if (0 < intval($month)) {
            $pattern .= str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-';
        }

        return $pattern . '%';
    }



    /**
     * Fetch information needed for the additional query calls.
     *
     * @param integer $userId     Filter entries by user
     * @param integer $year       Filter entries by year
     * @param integer $month      Filter entries by month
     * @param integer $projectId  Filter entries by project
     * @param integer $customerId Filter entries by customer
     *
     * @return array
     */
    public function findByMonthWithExternalInformation($userId, $year, ?int $month, $projectId, $customerId)
    {
        $entityManager  = $this->getEntityManager();
        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('distinct e.ticket, ts.id, ts.url, ts.login, ts.password')
            ->from(\App\Entity\Entry::class, 'e')
            ->innerJoin('e.project', 'p')
            ->innerJoin('p.ticketSystem', 'ts')
            ->where('p.additionalInformationFromExternal = 1')
            ->andWhere('p.jiraId IS NOT NULL')
            ->orderBy('ts.id');


        if (0 < $userId) {
            $queryBuilder->andWhere('e.user = :user_id')
                ->setParameter(':user_id', $userId);
        }

        if (0 < $projectId) {
            $queryBuilder->andWhere('e.project = :project_id')
                ->setParameter(':project_id', $projectId);
        }

        if (0 < $customerId) {
            $queryBuilder->andWhere('e.customer = :customer_id')
                ->setParameter(':customer_id', $customerId);
        }

        if (0 < $year) {
            $pattern = $this->getDatePattern($year, $month);
            $queryBuilder->andWhere('e.day LIKE :month')
                ->setParameter(':month', $pattern);
        }

        return $queryBuilder->getQuery()->getResult();
    }



    /**
     * get all entries of a user on a specific day
     *
     * @param integer $userId Filter by user ID
     * @param string  $day    Filter by date
     *
     * @return array
     */
    public function findByDay($userId, $day)
    {
        $entityManager = $this->getEntityManager();

        $query = $entityManager->createQuery(
            'SELECT e FROM App\Entity\Entry e'
            . ' WHERE e.user = :user_id'
            . ' AND e.day = :day'
            . ' ORDER BY e.start ASC, e.end ASC, e.id ASC'
        )->setParameter('user_id', $userId)
            ->setParameter('day', $day);

        return $query->getResult();
    }


    /**
     * Returns work log entries for a specific user within a time range.
     * Uses raw SQL for performance/legacy reasons? Refactored to use prepared statements.
     *
     * This method retrieves entries based on working days rather than calendar days.
     * It converts the requested number of working days to calendar days using getCalendarDaysByWorkDays().
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function getEntriesByUser(int $userId, int $days = 3, bool $showFuture = true): array
    {
        $today = $this->clock->today();
        $calendarDays = $this->getCalendarDaysByWorkDays($days);

        $connection = $this->getEntityManager()->getConnection();
        $params = [
            'userId' => $userId,
            // Format date for SQL parameter binding
            'fromDate' => $today->sub(new \DateInterval('P' . $calendarDays . 'D'))->format('Y-m-d'),
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
        $sql['from'] = "FROM entries e LEFT JOIN projects p ON e.project_id = p.id LEFT JOIN ticket_systems t ON p.ticket_system = t.id";
        // Modified: Use parameter binding for start date
        $sql['where_day'] = "WHERE day >= :fromDate";

        if (! $showFuture) {
            // Modified: Use parameter binding for today's date
            $sql['where_future'] = "AND day <= :today";
            $params['today'] = $today->format('Y-m-d');
        }

        // Modified: Use parameter binding for user ID
        $sql['where_user'] = 'AND user_id = :userId';
        $sql['order'] = "ORDER BY day DESC, start DESC";

        // Modified: Use prepare and executeQuery with parameters
        $stmt = $connection->prepare(implode(" ", $sql));
        $result = $stmt->executeQuery($params)->fetchAllAssociative(); // Use fetchAllAssociative for DBAL 3+

        $data = [];
        if (count($result)) {
            foreach ($result as &$line) {
                $line['user'] = (int) $line['user'];
                $line['customer'] = (int) $line['customer'];
                $line['project'] = (int) $line['project'];
                $line['activity'] = (int) $line['activity'];
                $line['duration'] = TimeHelper::formatDuration((int) $line['duration']);
                $line['class'] = (int) $line['class'];
                $data[] = ['entry' => $line];
            }
        }

        return $data;
    }

    /**
     * Get array of entries of given user and ticketsystem which should be synced to the ticketsystem.
     * Ordered by date, starttime desc
     *
     * @param integer $userId
     * @param integer $ticketSystemId
     * @param integer $maxResults       (optional) max number of results to be returned
     *                                  if null: no result limitation
     * @return Entry[]
     */
    public function findByUserAndTicketSystemToSync($userId, $ticketSystemId, $maxResults = null)
    {
        $queryBuilder = $this->getEntityManager()->createQueryBuilder();
        $queryBuilder
            ->select('e')
            ->from(\App\Entity\Entry::class, 'e')
            ->join(\App\Entity\Project::class, 'p', Join::WITH, 'e.project = p.id')
            ->where('e.user = :user_id')
            ->andWhere('e.syncedToTicketsystem = false')
            ->andWhere('p.ticketSystem = :ticket_system_id')
            ->setParameter('user_id', $userId)
            ->setParameter('ticket_system_id', $ticketSystemId)
            ->orderBy('e.day', 'DESC')
            ->addOrderBy('e.start', 'DESC');

        if ((int) $maxResults > 0) {
            $queryBuilder->setMaxResults((int) $maxResults);
        }

        return $queryBuilder->getQuery()->getResult();
    }


    /**
     * Query summary information regarding the current entry for the following
     * scopes: customer, project, activity, ticket
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function getEntrySummary(int $entryId, int $userId, array $data): array
    {
        $entry = $this->find($entryId);

        $connection = $this->getEntityManager()->getConnection();

        $sql = ['customer' => [], 'project' => [], 'ticket' => []];

        // customer total / customer total by current user
        $sql['customer']['select'] = "SELECT 'customer' AS scope,
            c.name AS name,
            COUNT(e.id) AS entries,
            SUM(e.duration) AS total,
            SUM(IF(e.user_id = {$userId} , e.duration, 0)) AS own,
            0 as estimation";
        $sql['customer']['from'] = "FROM entries e";
        $sql['customer']['join_c'] = "LEFT JOIN customers c ON c.id = e.customer_id";
        if ($entry->getCustomer()) {
            $sql['customer']['where_c'] = "WHERE e.customer_id = " . (int) $entry->getCustomer()->getId();
        } else {
            $sql['customer']['where_c'] = '';
        }

        // project total / project total by current user
        $sql['project']['select'] = "SELECT 'project' AS scope,
            CONCAT(p.name) AS name,
            COUNT(e.id) AS entries,
            SUM(e.duration) AS total,
            SUM(IF(e.user_id = {$userId} , e.duration, 0)) AS own,
            p.estimation AS estimation";
        $sql['project']['from'] = "FROM entries e";
        $sql['project']['join_c'] = "LEFT JOIN customers c ON c.id = e.customer_id";
        $sql['project']['join_p'] = "LEFT JOIN projects p ON p.id=e.project_id";
        $sql['project']['where_c'] = $entry->getCustomer() ? ("WHERE e.customer_id = " . (int) $entry->getCustomer()->getId()) : '';
        $sql['project']['where_p'] = $entry->getProject() ? ("AND e.project_id = " . (int) $entry->getProject()->getId()) : '';

        // activity total / activity total by current user
        if (is_object($entry->getActivity())) {
            $sql['activity']['select'] = "SELECT 'activity' AS scope,
                CONCAT(a.name) AS name,
                COUNT(e.id) AS entries,
                SUM(e.duration) AS total,
                SUM(IF(e.user_id = {$userId} , e.duration, 0)) AS own,
                0 as estimation";
            $sql['activity']['from'] = "FROM entries e";
            $sql['activity']['join_c'] = "LEFT JOIN customers c ON c.id = e.customer_id";
            $sql['activity']['join_p'] = "LEFT JOIN projects p ON p.id=e.project_id";
            $sql['activity']['join_a'] = "LEFT JOIN activities a ON a.id=e.activity_id";
            $sql['activity']['where_c'] = $entry->getCustomer() ? ("WHERE e.customer_id = " . (int) $entry->getCustomer()->getId()) : '';
            $sql['activity']['where_p'] = $entry->getProject() ? ("AND e.project_id = " . (int) $entry->getProject()->getId()) : '';
            $sql['activity']['where_a'] = $entry->getActivity() ? ("AND e.activity_id = " . (int) $entry->getActivity()->getId()) : '';
        } else {
            $sql['activity']['select'] = "SELECT 'activity' AS scope, '' AS name, 0 as entries, 0 as total, 0 as own";
        }

        if ($entry->getTicket() !== null && $entry->getTicket() !== '') {
            // ticket total / ticket total by current user
            $sql['ticket']['select'] = "SELECT 'ticket' AS scope,
                ticket AS name,
                COUNT(id) AS entries,
                SUM(duration) AS total,
                SUM(IF(user_id = {$userId}, duration, 0)) AS own,
                0 as estimation";
            $sql['ticket']['from'] = "FROM entries";
            $sql['ticket']['where'] = "WHERE ticket = '" . addslashes((string) $entry->getTicket()) . "'";
        } else {
            $sql['ticket']['select'] = "SELECT 'ticket' AS scope, '' AS name, 0 as entries, 0 as total, 0 as own, 0 AS estimation";
        }

        $stmt = $connection->query(
            implode(" ", $sql['customer'])
            . ' UNION ' . implode(" ", $sql['project'])
            . ' UNION ' . implode(" ", $sql['activity'])
            . ' UNION ' . implode(" ", $sql['ticket'])
        );
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $data['customer']   = $result[0];
        $data['project']    = $result[1];
        $data['activity']   = $result[2];
        $data['ticket']     = $result[3];

        return $data;
    }


    /**
     * Query the current user's work by given period.
     * Uses raw SQL. Refactored to use prepared statements.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function getWorkByUser(int $userId, int $period = self::PERIOD_DAY): array
    {
        $today = $this->clock->today();
        $connection = $this->getEntityManager()->getConnection();
        $params = ['userId' => $userId];

        $sql['select'] = "SELECT COUNT(id) AS count, SUM(duration) AS duration";
        $sql['from'] = "FROM entries";
        // Modified: Use parameter binding for user ID
        $sql['where_user'] = "WHERE user_id = :userId";

        switch ($period) {
            case self::PERIOD_DAY:
                // Modified: Use parameter binding for today's date
                $sql['where_day'] = "AND day = :todayDate";
                $params['todayDate'] = $today->format('Y-m-d');
                break;
            case self::PERIOD_WEEK:
                // Modified: Use parameter binding for year and week
                $sql['where_year'] = "AND YEAR(day) = :year";
                // Assuming WEEK(day, 1) aligns with ISO-8601 week (starts Monday) like PHP 'W'
                $sql['where_week'] = "AND WEEK(day, 1) = :week";
                $params['year'] = $today->format('Y');
                $params['week'] = $today->format('W');
                break;
            case self::PERIOD_MONTH:
                // Modified: Use parameter binding for year and month
                $sql['where_year'] = "AND YEAR(day) = :year";
                $sql['where_month'] = "AND MONTH(day) = :month";
                $params['year'] = $today->format('Y');
                $params['month'] = $today->format('m');
                break;
        }

        // Modified: Use prepare and executeQuery with parameters
        $stmt = $connection->prepare(implode(" ", $sql));
        $result = $stmt->executeQuery($params)->fetchAllAssociative(); // Use fetchAllAssociative for DBAL 3+

        // Original code returned false for count, keeping that behavior
        return [
            'duration' => $result[0]['duration'] ?? 0, // Handle potential empty result
            'count'    => false,
        ];
    }

    /**
     * Get query of entries for given filter params
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
     * @return \Doctrine\ORM\Query
     * @throws \Exception
     */
    public function queryByFilterArray(array $arFilter = [])
    {
        $queryBuilder = $this->createQueryBuilder('e');

        if (isset($arFilter['customer']) && !is_null($arFilter['customer'])) {
            $queryBuilder
                ->andWhere('e.customer = :customer')
                ->setParameter('customer', (int) $arFilter['customer']);
        }

        if (isset($arFilter['project']) && !is_null($arFilter['project'])) {
            $queryBuilder
                ->andWhere('e.project = :project')
                ->setParameter('project', (int) $arFilter['project']);
        }

        if (isset($arFilter['user']) && !is_null($arFilter['user'])) {
            $queryBuilder
                ->andWhere('e.user = :user')
                ->setParameter('user', (int) $arFilter['user']);
        }

        if (isset($arFilter['teams']) && !is_null($arFilter['teams'])) {
            $queryBuilder
                ->join('e.user', 'u')
                ->join('u.teams', 't')
                ->andWhere('t.id = :team')
                ->setParameter('team', (int) $arFilter['teams']);
        }

        if (isset($arFilter['datestart']) && !is_null($arFilter['datestart'])) {
            $date = new \DateTime($arFilter['datestart']);
            $queryBuilder->andWhere('e.day >= :start')
                ->setParameter('start', $date->format('Y-m-d'));
        }

        if (isset($arFilter['dateend']) && !is_null($arFilter['dateend'])) {
            $date = new \DateTime($arFilter['dateend']);
            $queryBuilder->andWhere('e.day <= :end')
                ->setParameter('end', $date->format('Y-m-d'));
        }

        if (isset($arFilter['activity']) && !is_null($arFilter['activity'])) {
            $queryBuilder
                ->andWhere('e.activity = :activity')
                ->setParameter('activity', (int) $arFilter['activity']);
        }

        if (isset($arFilter['ticket']) && !is_null($arFilter['ticket'])) {
            $queryBuilder
                ->andWhere('e.ticket LIKE :ticket')
                ->setParameter('ticket', $arFilter['ticket']);
        }

        if (isset($arFilter['description']) && !is_null($arFilter['description'])) {
            $queryBuilder
                ->andWhere('e.description LIKE :description')
                ->setParameter('description', '%' . $arFilter['description'] . '%');
        }

        if (isset($arFilter['maxResults']) && (int) $arFilter['maxResults'] > 0) {
            $queryBuilder
                ->orderBy('e.id', 'DESC')
                ->setMaxResults((int) $arFilter['maxResults']);
        }

        //pagination offset
        if (isset($arFilter['page']) && isset($arFilter['maxResults'])) {
            $queryBuilder
                ->setFirstResult((int) $arFilter['page'] * $arFilter['maxResults']);
        }

        if (isset($arFilter['visibility_user']) && !is_null($arFilter['visibility_user'])) {
            $queryBuilder
                ->andWhere('e.user = :vis_user')
                ->setParameter('vis_user', (int) $arFilter['visibility_user']);
        }

        return $queryBuilder->getQuery();
    }

    /**
     * Get array of entries for given filter params
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
     * @return array
     * @throws \Exception
     */
    public function findByFilterArray(array $arFilter = [])
    {
        return $this->queryByFilterArray($arFilter)->getResult();
    }

    /**
     * Get a list of activities with the total time booked on the ticket
     */
    public function getActivitiesWithTime(string $ticketname): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = "SELECT name, SUM(duration) AS total_time
                FROM entries
                LEFT JOIN  activities
                ON entries.activity_id = activities.id
                WHERE entries.ticket = :ticketname
                GROUP BY activity_id";

        $statement = $connection->prepare($sql);
        $statement->bindValue(':ticketname', $ticketname);
        return $statement->executeQuery()->fetchAllAssociative();
    }

    /**
     * Get a list of usernames that worked on the ticket and the total time they spent on it.
     */
    public function getUsersWithTime(string $ticketname): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = "SELECT username, SUM(duration) AS total_time
                FROM users, entries
                WHERE entries.ticket = :ticketname
                AND users.id = entries.user_id
                GROUP BY username";

        $statement = $connection->prepare($sql);
        $statement->bindValue(':ticketname', $ticketname);
        return $statement->executeQuery()->fetchAllAssociative();
    }
}
