<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

/**
 * Netresearch Timetracker
 *
 * PHP version 5
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Service
 * @author     Michael Lühr <michael.luehr@netresearch.de>
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */

namespace Netresearch\TimeTrackerBundle\Services;

use Netresearch\TimeTrackerBundle\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class Export
 *
 * @category   Netresearch
 * @package    Timetracker
 * @subpackage Service
 * @author     Michael Lühr <michael.luehr@netresearch.de>
 * @author     Various Artists <info@netresearch.de>
 * @license    http://www.gnu.org/licenses/agpl-3.0.html GNU AGPl 3
 * @link       http://www.netresearch.de
 */
class Export
{
    /**
     * @var null|ContainerInterface
     */
    protected $container = null;

    /**
     * mandatory dependency the service container
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    /**
     * Returns entries filtered and ordered.
     *
     * @param integer $userId Filter entries by user
     * @param integer $year   Filter entries by year
     * @param integer $month  Filter entries by month
     * @param array   $arSort Sort result by given fields
     *
     * @return mixed
     */
    public function exportEntries($userId,$year, $month, array $arSort = null)
    {
        /** @var \Netresearch\TimeTrackerBundle\Entity\Entry[] $arEntries */
        $arEntries = $this->getEntryRepository()
            ->findByDate($userId, $year, $month, $arSort);

        return $arEntries;
    }

    /**
     * Returns user name for given user ID.
     *
     * @param integer $userId User ID
     *
     * @return string $username - the name of the user or all if no valid user id is provided
     */
    public function getUsername($userId = null)
    {
        $username = 'all';
        if (0 < (int) $userId) {
            /* @var $user User */
            $user = $this->container->get('doctrine')
                ->getRepository('NetresearchTimeTrackerBundle:User')
                ->find($userId);
            $username = $user->getUsername();
        }

        return $username;
    }

    /**
     * returns the entry repository
     *
     * @return \Netresearch\TimeTrackerBundle\Repository\EntryRepository
     */
    protected function getEntryRepository()
    {
        return $this->container->get('doctrine')->getRepository('NetresearchTimeTrackerBundle:Entry');
    }
}
