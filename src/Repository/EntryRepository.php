<?php declare(strict_types=1);
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

use Exception;
use DateTime;
use DateInterval;
use PDO;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\Query\Expr\Join;
use App\Entity\Entry;
use App\Entity\User;
use DateTimeZone;
use Doctrine\ORM\EntityRepository;

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
class EntryRepository extends EntityRepository
{
    final public const PERIOD_DAY   = 1;
    final public const PERIOD_WEEK  = 2;
    final public const PERIOD_MONTH = 3;

    /**
     * Returns count of calendar days which include given amount of working days.
     */
    public static function getCalendarDaysByWorkDays(int $workingDays): int
    {
        $workingDays = (int) $workingDays;
        if ($workingDays < 1) {
            return 0;
        }

        // Calculate calendar days from given work days
        $weeks    = floor((int) $workingDays / 5);
        $restDays = ((int) $workingDays) % 5;

        if (0 === $restDays) {
            return $weeks * 7;
        }

        $dayOfWeek = date('w');

        switch ($dayOfWeek) {
        case 6:
            $restDays++;
            break;
        case 7:
            $restDays += 2;
            break;
        default:
            if ($dayOfWeek <= $restDays) {
                $restDays += 2;
            }
            break;
        }

        return ($weeks * 7) + $restDays;
    }

    /**
     * Returns work log entries for user and recent days.
     *
     * @throws Exception
     */
    public function findByRecentDaysOfUser(User $user, int $days = 3): array
    {
        $fromDate = new DateTime();
        $fromDate->setTime(0, 0);
        $calendarDays = self::getCalendarDaysByWorkDays($days);
        $fromDate->sub(new DateInterval('P'.$calendarDays.'D'));

        $em    = $this->getEntityManager();
        $query = $em->createQuery(
            'SELECT e FROM App:Entry e'
            .' WHERE e.user = :user_id AND e.day >= :fromDate'
            .' ORDER BY e.day, e.start ASC'
        )->setParameter('user_id', $user->getId())->setParameter('fromDate', $fromDate);

        return $query->getResult();
    }

    /**
     * get all entries of a user in a given year and month.
     *
     * @return Entry[]
     */
    public function findByDate(
        int $userId,
        int $year,
        int $month = null,
        int $projectId = null,
        int $customerId = null,
        array $arSort = null
    ): array {
        if (null === $arSort) {
            $arSort = [
                'entry.day'   => true,
                'entry.start' => true,
            ];
        }

        $qb = $this->createQueryBuilder('entry');

        $qb->select('entry')
            ->leftJoin('entry.user', 'user')
        ;

        foreach ($arSort as $strField => $bAsc) {
            $qb->addOrderBy($strField, $bAsc ? 'ASC' : 'DESC');
        }

        if (0 < (int) $userId) {
            $qb->andWhere('entry.user = :user_id');
            $qb->setParameter('user_id', $userId);
        }
        if (0 < (int) $projectId) {
            $qb->andWhere('entry.project = :project_id');
            $qb->setParameter('project_id', $projectId);
        }
        if (0 < (int) $customerId) {
            $qb->andWhere('entry.customer = :customer_id');
            $qb->setParameter('customer_id', $customerId);
        }
        if (0 < (int) $year) {
            if (0 < $month) {
                $date_min = new DateTime($year.'-01-01 00:00');
                $date_max = DateTime::createFromInterface($date_min);
                $date_max->modify('first day of next year');
            } else {
                $date_min = new DateTime($year.'-'.$month.'-01 00:00');
                $date_max = DateTime::createFromInterface($date_min);
                $date_max->modify('first day of next month');
            }

            $qb->andWhere("entry.day >= '".$date_min->format('c')."'");
            $qb->andWhere("entry.day <= '".$date_max->format('c')."'");
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Returns the date pattern for the repository queries according to year
     * an month.
     */
    protected function getDatePattern(int $year, int $month = null): string
    {
        $pattern = $year.'-';
        if (0 < (int) $month) {
            $pattern .= str_pad($month, 2, '0', \STR_PAD_LEFT).'-';
        }
        $pattern .= '%';

        return $pattern;
    }

    /**
     * Fetch information needed for the additional query calls.
     */
    public function findByMonthWithExternalInformation(int $userId, int $year, int $month, int $projectId, int $customerId): array
    {
        $em = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->select('distinct e.ticket, ts.id, ts.url, ts.login, ts.password')
            ->from('App:Entry', 'e')
            ->innerJoin('e.project', 'p', 'e.projectId = p.id')
            ->innerJoin('p.ticketSystem', 'ts', 'p.ticketSystem = ts.id')
            ->where('p.additionalInformationFromExternal = 1')
            ->andWhere('p.jiraId IS NOT NULL')
            ->orderBy('ts.id')
        ;

        if (0 < $userId) {
            $qb->andWhere('e.user = :user_id')
                ->setParameter(':user_id', $userId)
            ;
        }
        if (0 < $projectId) {
            $qb->andWhere('e.project = :project_id')
                ->setParameter(':project_id', $projectId)
            ;
        }
        if (0 < $customerId) {
            $qb->andWhere('e.customer = :customer_id')
                ->setParameter(':customer_id', $customerId)
            ;
        }

        if (0 < $year) {
            $pattern = $this->getDatePattern($year, $month);
            $qb->andWhere('e.day LIKE :month')
                ->setParameter(':month', $pattern)
            ;
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * get all entries of a user on a specific day.
     */
    public function findByDay(int $userId, string $day): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e')
            ->where('e.user = :user_id')
            ->setParameter('user_id', $userId)
            ->andWhere('e.day = :day')
            ->setParameter('day', $day)
            ->orderBy('e.start', 'ASC')
            ->addOrderBy('e.end', 'ASC')
            ->addOrderBy('e.id', 'ASC')
        ;

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * Get array of entries of given user.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function getEntriesByUser(int $userId, int $days = 3, bool $showFuture = true): array
    {
        $calendarDays = self::getCalendarDaysByWorkDays($days);

        $date_min = new DateTime();
        $date_min->modify('-'.$calendarDays.' days 00:00');

        $date_max = new DateTime();
        $date_max->modify('tomorrow 00:00');

        $qb = $this->createQueryBuilder('e')
            ->select('e')
            ->leftJoin('App:Project', 'p', Join::WITH, 'e.project = p.id')
            ->leftJoin('App:TicketSystem', 't', Join::WITH, 'p.ticketSystem = t.id')
            ->where("e.day >= '".$date_min->format('c')."'")
        ;
        if (!$showFuture) {
            $qb->andWhere("day <= '".$date_max->format('c')."'");
        }
        $qb->andWhere('e.user = :user_id')
            ->setParameter('user_id', $userId)
            ->orderBy('e.day', 'DESC')
            ->addOrderBy('e.start', 'DESC')
        ;

        return $qb->getQuery()->getResult();
    }

    /**
     * Get array of entries of given user and ticketsystem which should be synced to the ticketsystem.
     * Ordered by date, starttime desc.
     *
     * @param int $userId
     * @param int $ticketSystemId
     * @param int $maxResults     (optional) max number of results to be returned
     *                            if null: no result limitation
     *
     * @return Entry[]
     */
    public function findByUserAndTicketSystemToSync(int $userId, int $ticketSystemId, int $maxResults = null): array
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
            ->addOrderBy('e.start', Criteria::DESC)
        ;

        if ((int) $maxResults > 0) {
            $qb->setMaxResults((int) $maxResults);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Query summary information regarding the current entry for the following
     * scopes: customer, project, activity, ticket.
     *
     * @param int   $entryId The current entry's identifier
     * @param int   $userId  The current user's identifier
     * @param array $data    The initial (default) summary
     *
     * @throws \Doctrine\DBAL\Exception
     *
     * @return array
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
        $sql['customer']['from']    = 'FROM entries e';
        $sql['customer']['join_c']  = 'LEFT JOIN customers c ON c.id = e.customer_id';
        $sql['customer']['where_c'] = 'WHERE e.customer_id = '.(int) $entry->getCustomer()->getId();

        // project total / project total by current user
        $sql['project']['select'] = "SELECT 'project' AS scope,
            CONCAT(p.name) AS name,
            COUNT(e.id) AS entries,
            SUM(e.duration) AS total,
            SUM(IF(e.user_id = {$userId} , e.duration, 0)) AS own,
            p.estimation AS estimation";
        $sql['project']['from']    = 'FROM entries e';
        $sql['project']['join_c']  = 'LEFT JOIN customers c ON c.id = e.customer_id';
        $sql['project']['join_p']  = 'LEFT JOIN projects p ON p.id=e.project_id';
        $sql['project']['where_c'] = 'WHERE e.customer_id = '.(int) $entry->getCustomer()->getId();
        $sql['project']['where_p'] = 'AND e.project_id = '.(int) $entry->getProject()->getId();

        // activity total / activity total by current user
        if (\is_object($entry->getActivity())) {
            $sql['activity']['select'] = "SELECT 'activity' AS scope,
                CONCAT(a.name) AS name,
                COUNT(e.id) AS entries,
                SUM(e.duration) AS total,
                SUM(IF(e.user_id = {$userId} , e.duration, 0)) AS own,
                0 as estimation";
            $sql['activity']['from']    = 'FROM entries e';
            $sql['activity']['join_c']  = 'LEFT JOIN customers c ON c.id = e.customer_id';
            $sql['activity']['join_p']  = 'LEFT JOIN projects p ON p.id=e.project_id';
            $sql['activity']['join_a']  = 'LEFT JOIN activities a ON a.id=e.activity_id';
            $sql['activity']['where_c'] = 'WHERE e.customer_id = '.(int) $entry->getCustomer()->getId();
            $sql['activity']['where_p'] = 'AND e.project_id = '.(int) $entry->getProject()->getId();
            $sql['activity']['where_a'] = 'AND e.activity_id = '.(int) $entry->getActivity()->getId();
        } else {
            $sql['activity']['select'] = "SELECT 'activity' AS scope, '' AS name, 0 as entries, 0 as total, 0 as own";
        }

        if ('' !== $entry->getTicket()) {
            // ticket total / ticket total by current user
            $sql['ticket']['select'] = "SELECT 'ticket' AS scope,
                ticket AS name,
                COUNT(id) AS entries,
                SUM(duration) AS total,
                SUM(IF(user_id = {$userId}, duration, 0)) AS own,
                0 as estimation";
            $sql['ticket']['from']  = 'FROM entries';
            $sql['ticket']['where'] = "WHERE ticket = '".addslashes($entry->getTicket())."'";
        } else {
            $sql['ticket']['select'] = "SELECT 'ticket' AS scope, '' AS name, 0 as entries, 0 as total, 0 as own, 0 AS estimation";
        }

        $stmt = $connection->executeQuery(
            implode(' ', $sql['customer'])
            .' UNION '.implode(' ', $sql['project'])
            .' UNION '.implode(' ', $sql['activity'])
            .' UNION '.implode(' ', $sql['ticket'])
        );
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data['customer'] = $result[0];
        $data['project']  = $result[1];
        $data['activity'] = $result[2];
        $data['ticket']   = $result[3];

        return $data;
    }

    /**
     * Query the current user's work by given period.
     *
     * @throws \Doctrine\DBAL\Exception
     */
    public function getWorkByUser(int $userId, int $period = self::PERIOD_DAY): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COUNT(e.id) AS count')
            ->addSelect('SUM(e.duration) AS duration')
            ->where('e.user = :user_id')
        ;

        $date_min = new DateTime('now', new DateTimeZone('UTC'));
        $date_max = new DateTime('now', new DateTimeZone('UTC'));
        switch ($period) {
        case self::PERIOD_DAY:
            $date_min->modify('today 00:00');
            $date_max->modify('tomorrow 00:00');
            break;
        case self::PERIOD_WEEK:
            $date_min->modify('first day of this week 00:00');
            $date_max->modify('first day of next week midnight 00:00');
            break;
        case self::PERIOD_MONTH:
            $date_min->modify('first day of this month');
            $date_max->modify('first day of next month 00:00');
            break;
        }

        $qb->andWhere('e.day BETWEEN :dateMin AND :dateMax')
            ->setParameters(
                [
                    'user_id' => $userId,
                    'dateMin' => $date_min->format('c'),
                    'dateMax' => $date_max->format('c'),
                ]
            )
        ;

        $result = $qb->getQuery()->getSingleResult();

        return [
            'duration' => $result['duration'],
            'count'    => false,
        ];
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
     * @return array
     */
    public function findByFilterArray(array $arFilter = []): array
    {
        $queryBuilder = $this->createQueryBuilder('e');

        if (isset($arFilter['customer']) && null !== $arFilter['customer']) {
            $queryBuilder
                ->andWhere('e.customer = :customer')
                ->setParameter('customer', (int) $arFilter['customer'])
            ;
        }

        if (isset($arFilter['project']) && null !== $arFilter['project']) {
            $queryBuilder
                ->andWhere('e.project = :project')
                ->setParameter('project', (int) $arFilter['project'])
            ;
        }

        if (isset($arFilter['user']) && null !== $arFilter['user']) {
            $queryBuilder
                ->andWhere('e.user = :user')
                ->setParameter('user', (int) $arFilter['user'])
            ;
        }

        if (isset($arFilter['teams']) && null !== $arFilter['teams']) {
            $queryBuilder
                ->join('e.user', 'u')
                ->join('u.teams', 't')
                ->andWhere('t.id = :team')
                ->setParameter('team', (int) $arFilter['teams'])
            ;
        }

        if (isset($arFilter['datestart']) && null !== $arFilter['datestart']) {
            $date = new DateTime($arFilter['datestart']);
            $queryBuilder->andWhere('e.day >= :start')
                ->setParameter('start', $date->format('Y-m-d'))
            ;
        }

        if (isset($arFilter['dateend']) && null !== $arFilter['dateend']) {
            $date = new DateTime($arFilter['dateend']);
            $queryBuilder->andWhere('e.day <= :end')
                ->setParameter('end', $date->format('Y-m-d'))
            ;
        }

        if (isset($arFilter['activity']) && null !== $arFilter['activity']) {
            $queryBuilder
                ->andWhere('e.activity = :activity')
                ->setParameter('activity', (int) $arFilter['activity'])
            ;
        }

        if (isset($arFilter['ticket']) && null !== $arFilter['ticket']) {
            $queryBuilder
                ->andWhere('e.ticket LIKE :ticket')
                ->setParameter('ticket', $arFilter['ticket'])
            ;
        }

        if (isset($arFilter['description']) && null !== $arFilter['description']) {
            $queryBuilder
                ->andWhere('e.description LIKE :description')
                ->setParameter('description', '%'.$arFilter['description'].'%')
            ;
        }

        if (isset($arFilter['maxResults']) && (int) $arFilter['maxResults'] > 0) {
            $queryBuilder
                ->orderBy('e.id', Criteria::DESC)
                ->setMaxResults((int) $arFilter['maxResults'])
            ;
        }

        if (isset($arFilter['visibility_user']) && null !== $arFilter['visibility_user']) {
            $queryBuilder
                ->andWhere('e.user = :vis_user')
                ->setParameter('vis_user', (int) $arFilter['visibility_user'])
            ;
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get a list of activities with the total time booked on the ticket.
     *
     * @param string $ticket_name Name of the ticket
     *
     * @return array Names of the activities with their total time in seconds
     */
    public function getActivitiesWithTime(string $ticket_name): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql        = 'SELECT name, SUM(duration) AS total_time
                FROM entries
                LEFT JOIN  activities
                ON entries.activity_id = activities.id
                WHERE entries.ticket = :ticket_name
                GROUP BY activity_id';

        $stmt = $connection->prepare($sql);
        $stmt->execute([':ticket_name' => $ticket_name]);

        return $stmt->fetchAllAssociative(PDO::FETCH_ASSOC);
    }

    /**
     * Get a list of usernames that worked on the ticket and the total time they spent on it.
     *
     * @param string $ticket_name Name of the ticket
     *
     * @return array usernames with their total time in seconds
     */
    public function getUsersWithTime(string $ticket_name): array
    {
        $connection = $this->getEntityManager()->getConnection();
        $sql        = 'SELECT username, SUM(duration) AS total_time
                FROM users, entries
                WHERE entries.ticket = :ticket_name
                AND users.id = entries.user_id
                GROUP BY username';

        $stmt = $connection->prepare($sql);
        $stmt->execute([':ticket_name' => $ticket_name]);

        return $stmt->fetchAllAssociative(PDO::FETCH_ASSOC);
    }
}
