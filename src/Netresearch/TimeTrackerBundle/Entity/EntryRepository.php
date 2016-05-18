<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Netresearch\TimeTrackerBundle\Helper\TimeHelper;

use Doctrine\ORM\EntityRepository;

class EntryRepository extends EntityRepository
{
    const PERIOD_DAY = 1;
    const PERIOD_WEEK = 2;
    const PERIOD_MONTH = 3;

    /**
     * get all entries of a user in a given period of days
     * 
     * @param int $userId 
     * @param int $days 
     * @return array
     */

    public static function getCalendarDaysByWorkDays($days)
    {
        $days = (int) $days;
        if ($days < 1)
            return 0;

        // Calculate calendar days from given work days
        $weeks = floor((int) $days / 5);
        $restDays = ((int) $days) % 5;

        if ($restDays == 0) {
            return $weeks * 7;
        }

        $dow = date("w");

        switch ($dow) {
            case 6:
                $restDays++;
                break;
            case 7:
                $restDays += 2;
                break;
            default:
                if ($dow <= $restDays)
                    $restDays += 2;
                break;
        }

        $calendarDays = ($weeks * 7) + $restDays;
        return $calendarDays;
    }

    public function findByRecentDaysOfUser($userId, $days=3)
    {
        $fromDate = new \DateTime();
        $fromDate->setTime(0, 0);
        $calendarDays = self::getCalendarDaysByWorkDays($days);
        $fromDate->sub(new \DateInterval('P' . $calendarDays . 'D'));
        
        $em = $this->getEntityManager();
        $query = $em->createQuery(
            'SELECT e FROM NetresearchTimeTrackerBundle:Entry e'
            . ' WHERE e.user = :user_id AND e.day >= :fromDate'
            . ' ORDER BY e.day, e.start ASC'
        )->setParameter('user_id', $userId)->setParameter('fromDate', $fromDate);

        return $query->getResult();
    }

    /**
     * get all entries of a user in a given year and month
     * 
     * @param int $userId 
     * @param int $year
     * @param int $month
     * @param array $arSort Sort result by given fields
     *
     * @return \Netresearch\TimeTrackerBundle\Entity\Entry[]
     */
    public function findByDate($userId, $year, $month = null, array $arSort = null)
    {
        if (null === $arSort) {
            $arSort = array(
                'entry.day' => true,
                'entry.start' => true,
            );
        }

        $strOrderBy = $this->getOrderByClause($arSort);

        $em = $this->getEntityManager();
        $pattern = $this->getDatePattern($year, $month);
        if (0 < (int) $userId) {
            $query = $em->createQuery(
                'SELECT entry FROM NetresearchTimeTrackerBundle:Entry entry'
                . ' WHERE entry.user = :user_id'
                . ' AND entry.day LIKE :month'
                . ' ORDER BY ' . $strOrderBy
            )->setParameter('user_id', $userId)
            ->setParameter('month', $pattern);
        } else {
            $query = $em->createQuery(
                'SELECT entry FROM NetresearchTimeTrackerBundle:Entry entry'
                . ' WHERE entry.day LIKE :month'
                . ' ORDER BY ' . $strOrderBy
            )->setParameter('month', $pattern);
        }
        return $query->getResult();
    }



    /**
     * Returns SQL ORDER BY clause from $arSort options.
     *
     * <code>
     * $arSort = array(
     *  'foofield' => true // first sort by this field ASCending
     *  'barfield' => false // second sort by this field DESCending
     * )
     * </code>
     *
     * @param array $arSort Fields for ORDER BY clause
     *
     * @return string
     */
    protected function getOrderByClause(array $arSort)
    {
        $arOrderBy = array();

        foreach ($arSort as $strField => $bAsc) {
            $arOrderBy[] = $strField . ($bAsc ? ' ASC' : ' DESC');
        }

        return implode(', ', $arOrderBy);
    }



    /**
     * Returns the date pattern for the repository queries according to year
     * an month
     *
     * @param int $year   the year, e.g. 2015
     * @param int $month  the month, e.g. 1 or null
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
     * fetches the information needed for the additional query calls
     *
     * @param $userId
     * @param $year
     * @param $month
     *
     * @return array
     */
    public function findByMonthWithExternalInformation($userId, $year, $month)
    {
        $pattern = $this->getDatePattern($year, $month);
        $em  = $this->getEntityManager();
        $qb = $em->createQueryBuilder()
            ->select('distinct e.ticket, ts.id, ts.url, ts.login, ts.password')
            ->from('NetresearchTimeTrackerBundle:Entry', 'e')
            ->innerJoin('e.project', 'p', 'e.projectId = p.id')
            ->innerJoin('p.ticketSystem', 'ts', 'p.ticketSystem = ts.id')
            ->where('p.additionalInformationFromExternal = 1')
            ->andWhere('e.day LIKE :month')
            ->andWhere('p.jiraId IS NOT NULL')
            ->orderBy('ts.id')
            ->setParameter(':month', $pattern)
        ;
        if (0 < $userId) {
            $qb->andWhere('e.user = :user_id')
                ->setParameter(':user_id', $userId);
        }

        $result = $qb->getQuery()->getResult();

        return $result;

    }
    
    /**
     * get all entries of a user on a specific day
     * 
     * @param int $userId 
     * @param date $day
     * @return array
     */
    public function findByDay($userId, $day)
    {
        $em = $this->getEntityManager();

        $query = $em->createQuery(
            'SELECT e FROM NetresearchTimeTrackerBundle:Entry e'
            . ' WHERE e.user = :user_id'
            . ' AND e.day = :day'
            . ' ORDER BY e.start ASC, e.end ASC, e.id ASC'
            )->setParameter('user_id', $userId)
            ->setParameter('day', $day);
    
        return $query->getResult();
    }


    /**
     * get array of entries of given user
     * 
     * @param int $userId 
     * @param int $days 
     *
     * @return array
     */
    public function getEntriesByUser($userId, $days = 3, $showFuture = true)
    {
        $calendarDays = self::getCalendarDaysByWorkDays($days);
        $connection = $this->getEntityManager()->getConnection();

        $sql = array();
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
        	e.duration";
        $sql['from'] = "FROM entries e";
        $sql['where_day'] = "WHERE day >= DATE_ADD(CURDATE(), INTERVAL -" . $calendarDays . " DAY)";

        if (! $showFuture)
            $sql['where_future'] = "AND day <= CURDATE()";

        $sql['where_user'] = "AND user_id = $userId";
        $sql['order'] = "ORDER BY day DESC, start DESC";

        $stmt = $connection->query(implode(" ", $sql));
        
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $data = array();
        if (count($result)) foreach ($result as &$line) {
            $line['user'] = (int) $line['user'];
            $line['customer'] = (int) $line['customer'];
            $line['project'] = (int) $line['project'];
            $line['activity'] = (int) $line['activity'];
            $line['duration'] = TimeHelper::formatDuration($line['duration']);
            $line['class'] = (int) $line['class'];
            $data[] = array('entry' => $line);
        }
        
        return $data;
    }
    
    /**
     * Query summary information regarding the current entry for the following
     * scopes: customer, project, activity, ticket
     * 
     * @param int $entryId The current entry's identifier
     * @param int $userId The current user's identifier
     * @param array $data The initial (default) summary
     * @return array
     */
    public function getEntrySummary($entryId, $userId, $data)
    {
        $entry = $this->find($entryId);
        
        $connection = $this->getEntityManager()->getConnection();
        
        $sql = array('customer' => array(), 'project' => array(), 'ticket' => array());

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

        $stmt = $connection->query(
                    implode(" ", $sql['customer']) 
                    . ' UNION ' . implode(" ", $sql['project'])
                    . ' UNION ' . implode(" ", $sql['activity']) 
                    . ' UNION ' . implode(" ", $sql['ticket']));
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

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
     * @return array
     */
    public function getWorkByUser($userId, $period = self::PERIOD_DAY)
    {
        $connection = $this->getEntityManager()->getConnection();
        
        $sql['select'] = "SELECT COUNT(id) AS count, SUM(duration) AS duration";
        $sql['from'] = "FROM entries";
        $sql['where_user'] = "WHERE user_id = " . $userId;
        
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

        $stmt = $connection->query(implode(" ", $sql));
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        $data = array('duration' => $result[0]['duration'], 'count' => FALSE);
        
        return $data;
    }
}
