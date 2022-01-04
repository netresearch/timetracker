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

use Exception;
use DateTime;
use DateInterval;
use PDO;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Query\Expr\Join;
use App\Entity\Entry;
use App\Entity\User;
use App\Helper\TimeHelper;

use Doctrine\ORM\EntityRepository;

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
class EntryRepository extends EntityRepository
{
    public final const PERIOD_DAY   = 1;
    public final const PERIOD_WEEK  = 2;
    public final const PERIOD_MONTH = 3;

    /**
     * Returns count of calendar days which include given amount of working days.
     *
     * @param int $workingDays Amount of working days.
     *
     * @return integer
     */
    public static function getCalendarDaysByWorkDays($workingDays)
    {
        $workingDays = (int) $workingDays;
        if ($workingDays < 1)
            return 0;

        // Calculate calendar days from given work days
        $weeks = floor((int) $workingDays / 5);
        $restDays = ((int) $workingDays) % 5;

        if ($restDays == 0) {
            return $weeks * 7;
        }

        $dayOfWeek = date("w");

        switch ($dayOfWeek) {
        case 6:
            $restDays++;
            break;
        case 7:
            $restDays += 2;
            break;
        default:
            if ($dayOfWeek <= $restDays)
                $restDays += 2;
            break;
        }

        $calendarDays = ($weeks * 7) + $restDays;

        return $calendarDays;
    }


    /**
     * Returns work log entries for user and recent days.
     *
     * @param User $user Filter by user ID
     * @param integer $days Filter by recent days
     *
     * @return array
     * @throws Exception
     */
    public function findByRecentDaysOfUser($user, $days = 3)
    {
        $fromDate = new DateTime();
        $fromDate->setTime(0, 0);
        $calendarDays = self::getCalendarDaysByWorkDays($days);
        $fromDate->sub(new DateInterval('P' . $calendarDays . 'D'));

        $em = $this->getEntityManager();
        $query = $em->createQuery(
            'SELECT e FROM App:Entry e'
            . ' WHERE e.user = :user_id AND e.day >= :fromDate'
            . ' ORDER BY e.day, e.start ASC'
        )->setParameter('user_id', $user->getId())->setParameter('fromDate', $fromDate);

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
     * @return Entry[]
     */
    public function findByDate($userId, $year, $month = null, $projectId = null, $customerId = null, $arSort = null)
    {
        if (null === $arSort) {
            $arSort = [
                'entry.day'   => true,
                'entry.start' => true,
            ];
        }

        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb->select('entry')
            ->from('App:Entry', 'entry')
            ->leftJoin('entry.user', 'user');

        foreach ($arSort as $strField => $bAsc) {
            $qb->addOrderBy($strField, $bAsc ? 'ASC' : 'DESC');
        }


        if (0 < (int) $userId) {
            $qb->andWhere('entry.user = :user_id');
            $qb->setParameter('user_id', $userId, PDO::PARAM_INT);
        }
        if (0 < (int) $projectId) {
            $qb->andWhere('entry.project = :project_id');
            $qb->setParameter('project_id', $projectId, PDO::PARAM_INT);
        }
        if (0 < (int) $customerId) {
            $qb->andWhere('entry.customer = :customer_id');
            $qb->setParameter('customer_id', $customerId, PDO::PARAM_INT);
        }
        if (0 < (int) $year) {
            $qb->andWhere(
                $qb->expr()->like('entry.day', ':month')
            );
            $qb->setParameter('month', $this->getDatePattern($year, $month), PDO::PARAM_STR);
        }

        return $qb->getQuery()->getResult();
    }



    /**
     * Returns the date pattern for the repository queries according to year
     * an month
     *
     * @param int $year  the year, e.g. 2015
     * @param int $month the month, e.g. 1 or null
     *
     * @return string e.g. 2015-01-%, 2015-%, if no month is set
     */
    protected function getDatePattern($year, $month = null)
    {
        $pattern = $year . '-';
        if (0 < intval($month)) {
            $pattern .= str_pad($month, 2, '0', STR_PAD_LEFT) . '-';
        }
        $pattern .= '%';

        return $pattern;
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
    public function findByMonthWithExternalInformation($userId, $year, $month, $projectId, $customerId)
    {
        $em  = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->select('distinct e.ticket, ts.id, ts.url, ts.login, ts.password')
            ->from('App:Entry', 'e')
            ->innerJoin('e.project', 'p', 'e.projectId = p.id')
            ->innerJoin('p.ticketSystem', 'ts', 'p.ticketSystem = ts.id')
            ->where('p.additionalInformationFromExternal = 1')
            ->andWhere('p.jiraId IS NOT NULL')
            ->orderBy('ts.id');


        if (0 < $userId) {
            $qb->andWhere('e.user = :user_id')
                ->setParameter(':user_id', $userId);
        }
        if (0 < $projectId) {
            $qb->andWhere('e.project = :project_id')
                ->setParameter(':project_id', $projectId);
        }
        if (0 < $customerId) {
            $qb->andWhere('e.customer = :customer_id')
                ->setParameter(':customer_id', $customerId);
        }

        if (0 < $year) {
            $pattern = $this->getDatePattern($year, $month);
            $qb->andWhere('e.day LIKE :month')
                ->setParameter(':month', $pattern);
        }

        $result = $qb->getQuery()->getResult();

        return $result;
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
        $em = $this->getEntityManager();

        $query = $em->createQuery(
            'SELECT e FROM App:Entry e'
            . ' WHERE e.user = :user_id'
            . ' AND e.day = :day'
            . ' ORDER BY e.start ASC, e.end ASC, e.id ASC'
        )->setParameter('user_id', $userId)
            ->setParameter('day', $day);

        return $query->getResult();
    }


    /**
     * Get array of entries of given user.
     *
     * @param integer $userId Filter by user ID
     * @param integer $days Filter by x days in past
     * @param boolean $showFuture Include work log entries from future
     *
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    public function getEntriesByUser($userId, $days = 3, $showFuture = true)
    {
        $calendarDays = self::getCalendarDaysByWorkDays($days);
        $connection = $this->getEntityManager()->getConnection();

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
        $sql['where_day'] = "WHERE day >= DATE_ADD(CURDATE(), INTERVAL -" . $calendarDays . " DAY)";

        if (! $showFuture) {
            $sql['where_future'] = "AND day <= CURDATE()";
        }

        $sql['where_user'] = "AND user_id = $userId";
        $sql['order'] = "ORDER BY day DESC, start DESC";

        $stmt = $connection->executeQuery(implode(" ", $sql));

        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        if (count($result)) foreach ($result as &$line) {
            $line['user'] = (int) $line['user'];
            $line['customer'] = (int) $line['customer'];
            $line['project'] = (int) $line['project'];
            $line['activity'] = (int) $line['activity'];
            $line['duration'] = TimeHelper::formatDuration($line['duration']);
            $line['class'] = (int) $line['class'];
            $data[] = ['entry' => $line];
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
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb
            ->select('e')
            ->from('App:Entry', 'e')
            ->join('App:Project', 'p', Join::WITH, 'e.project = p.id')
            ->where('e.user = :user_id')
            ->andWhere('e.syncedToTicketsystem = false')
            ->andWhere('p.ticketSystem = :ticket_system_id')
            ->setParameter('user_id', $userId)
            ->setParameter('ticket_system_id', $ticketSystemId)
            ->orderBy('e.day', Criteria::DESC)
            ->addOrderBy('e.start', Criteria::DESC);

        if ((int) $maxResults > 0) {
            $qb->setMaxResults((int) $maxResults);
        }

        return $qb->getQuery()->getResult();
    }


    /**
     * Query summary information regarding the current entry for the following
     * scopes: customer, project, activity, ticket
     *
     * @param integer $entryId The current entry's identifier
     * @param integer $userId The current user's identifier
     * @param array $data The initial (default) summary
     *
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    public function getEntrySummary($entryId, $userId, $data)
    {
        $entry = $this->find($entryId);

        $connection = $this->getEntityManager()->getConnection();

        $sql = ['customer' => [], 'project' => [], 'ticket' => []];

        // customer total / customer total by current user
        $sql['customer']['select'] = "SELECT 'customer' AS scope,
            c.name AS name,
            COUNT(e.id) AS entries,
            SUM(e.duration) AS total,
            SUM(IF(e.user_id = $userId , e.duration, 0)) AS own,
            0 as estimation";
        $sql['customer']['from'] = "FROM entries e";
        $sql['customer']['join_c'] = "LEFT JOIN customers c ON c.id = e.customer_id";
        $sql['customer']['where_c'] = "WHERE e.customer_id = " . (int) $entry->getCustomer()->getId();

        // project total / project total by current user
        $sql['project']['select'] = "SELECT 'project' AS scope,
            CONCAT(p.name) AS name,
            COUNT(e.id) AS entries,
            SUM(e.duration) AS total,
            SUM(IF(e.user_id = $userId , e.duration, 0)) AS own,
            p.estimation AS estimation";
        $sql['project']['from'] = "FROM entries e";
        $sql['project']['join_c'] = "LEFT JOIN customers c ON c.id = e.customer_id";
        $sql['project']['join_p'] = "LEFT JOIN projects p ON p.id=e.project_id";
        $sql['project']['where_c'] = "WHERE e.customer_id = " . (int) $entry->getCustomer()->getId();
        $sql['project']['where_p'] = "AND e.project_id = " . (int) $entry->getProject()->getId();

        // activity total / activity total by current user
        if (is_object($entry->getActivity())) {
            $sql['activity']['select'] = "SELECT 'activity' AS scope,
                CONCAT(a.name) AS name,
                COUNT(e.id) AS entries,
                SUM(e.duration) AS total,
                SUM(IF(e.user_id = $userId , e.duration, 0)) AS own,
                0 as estimation";
            $sql['activity']['from'] = "FROM entries e";
            $sql['activity']['join_c'] = "LEFT JOIN customers c ON c.id = e.customer_id";
            $sql['activity']['join_p'] = "LEFT JOIN projects p ON p.id=e.project_id";
            $sql['activity']['join_a'] = "LEFT JOIN activities a ON a.id=e.activity_id";
            $sql['activity']['where_c'] = "WHERE e.customer_id = " . (int) $entry->getCustomer()->getId();
            $sql['activity']['where_p'] = "AND e.project_id = " . (int) $entry->getProject()->getId();
            $sql['activity']['where_a'] = "AND e.activity_id = " . (int) $entry->getActivity()->getId();
        } else {
            $sql['activity']['select'] = "SELECT 'activity' AS scope, '' AS name, 0 as entries, 0 as total, 0 as own";
        }

        if ('' != $entry->getTicket()) {
            // ticket total / ticket total by current user
            $sql['ticket']['select'] = "SELECT 'ticket' AS scope,
                ticket AS name,
                COUNT(id) AS entries,
                SUM(duration) AS total,
                SUM(IF(user_id = $userId, duration, 0)) AS own,
                0 as estimation";
            $sql['ticket']['from'] = "FROM entries";
            $sql['ticket']['where'] = "WHERE ticket = '" . addslashes($entry->getTicket()) . "'";
        } else {
            $sql['ticket']['select'] = "SELECT 'ticket' AS scope, '' AS name, 0 as entries, 0 as total, 0 as own, 0 AS estimation";
        }

        $stmt = $connection->executeQuery(
            implode(" ", $sql['customer'])
            . ' UNION ' . implode(" ", $sql['project'])
            . ' UNION ' . implode(" ", $sql['activity'])
            . ' UNION ' . implode(" ", $sql['ticket'])
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data['customer']   = $result[0];
        $data['project']    = $result[1];
        $data['activity']   = $result[2];
        $data['ticket']     = $result[3];

        return $data;
    }


    /**
     * Query the current user's work by given period
     *
     * @param int $userId The current user's identifier
     * @param int $period The requested period (day / week / month)
     *
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    public function getWorkByUser($userId, $period = self::PERIOD_DAY)
    {
        $sql = [];
        $connection = $this->getEntityManager()->getConnection();

        $sql['select'] = "SELECT COUNT(id) AS count, SUM(duration) AS duration";
        $sql['from'] = "FROM entries";
        $sql['where_user'] = "WHERE user_id = " . intval($userId);

        switch($period) {
        case self::PERIOD_DAY :
            $sql['where_day'] = "AND day = CURDATE()";
            break;
        case self::PERIOD_WEEK :
            $sql['where_year'] = "AND YEAR(day) = YEAR(CURDATE())";
            $sql['where_week'] = "AND WEEK(day, 1) = WEEK(CURDATE(), 1)";
            break;
        case self::PERIOD_MONTH :
            $sql['where_year'] = "AND YEAR(day) = YEAR(CURDATE())";
            $sql['where_month']= "AND MONTH(day) = MONTH(CURDATE())";
            break;
        }

        $stmt   = $connection->executeQuery(implode(" ", $sql));
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [
            'duration' => $result[0]['duration'],
            'count'    => false,
        ];

        return $data;
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
     * @throws Exception
     */
    public function findByFilterArray($arFilter = [])
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
            $date = new DateTime($arFilter['datestart']);
            $queryBuilder->andWhere('e.day >= :start')
                ->setParameter('start', $date->format('Y-m-d'));
        }

        if (isset($arFilter['dateend']) && !is_null($arFilter['dateend'])) {
            $date = new DateTime($arFilter['dateend']);
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
                ->orderBy('e.id', Criteria::DESC)
                ->setMaxResults((int) $arFilter['maxResults']);
        }

        if (isset($arFilter['visibility_user']) && !is_null($arFilter['visibility_user'])) {
            $queryBuilder
                ->andWhere('e.user = :vis_user')
                ->setParameter('vis_user', (int) $arFilter['visibility_user']);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get a list of activities with the total time booked on the ticket
     *
     * @param string $ticket_name Name of the ticket
     *
     * @return array Names of the activities with their total time in seconds
     */
    public function getActivitiesWithTime(string $ticket_name)
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = "SELECT name, SUM(duration) AS total_time
                FROM entries
                LEFT JOIN  activities
                ON entries.activity_id = activities.id
                WHERE entries.ticket = :ticket_name
                GROUP BY activity_id";

        $stmt = $connection->prepare($sql);
        $stmt->execute([':ticket_name' => $ticket_name]);
        $result = $stmt->fetchAllAssociative(PDO::FETCH_ASSOC);
        return $result;
    }

    /**
     * Get a list of usernames that worked on the ticket and the total time they spent on it.
     *
     * @param string $ticket_name Name of the ticket
     *
     * @return array usernames with their total time in seconds
     */
    public function getUsersWithTime(string $ticket_name)
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql = "SELECT username, SUM(duration) AS total_time
                FROM users, entries
                WHERE entries.ticket = :ticket_name
                AND users.id = entries.user_id
                GROUP BY username";

        $stmt = $connection->prepare($sql);
        $stmt->execute([':ticket_name' => $ticket_name]);
        $result = $stmt->fetchAllAssociative(PDO::FETCH_ASSOC);
        return $result;
    }
}
