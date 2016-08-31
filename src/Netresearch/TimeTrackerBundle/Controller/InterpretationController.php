<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\EntryRepository;
use Netresearch\TimeTrackerBundle\Helper\TimeHelper;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Netresearch\TimeTrackerBundle\Model\Response;
use Symfony\Component\HttpFoundation\Request;

class InterpretationController extends BaseController
{
    private $cache = null;

    public function sortByName($a, $b) {
        return strcmp($b['name'], $a['name']);
    }

    public function getLastEntriesAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $query = $this->getQuery($request);
        } catch (\Exception $e) {
            $response = new Response($this->translate($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $query->add('orderBy', 'e.id DESC')
            ->setMaxResults(50);
        $entries = $query->getQuery()->getResult();
        $sum = $this->calculateSum($entries);
        $entrylist = array();
        foreach($entries AS &$entry) {
            $flatEntry = $entry->toArray();
            $flatEntry['duration'] = TimeHelper::formatDuration($flatEntry['duration']);
            $flatEntry['quota'] = TimeHelper::formatQuota($flatEntry['duration'], $sum);
            $entrylist[] = array('entry' => $flatEntry);
        }
        return new Response(json_encode($entrylist));
    }

    private function getCachedResult(Request $request)
    {
        if (null != $this->cache)
            return $this->cache;

        $query = $this->getQuery($request);
        $this->cache = $query->getQuery()->getResult();
        return $this->cache;
    }

    private function getCachedSum()
    {
        if (null == $this->cache)
            return 0;

        $sum = 0;
        foreach($this->cache AS &$entry)
            $sum += $entry->getDuration();
        return $sum;
    }

    private function calculateSum(&$entries)
    {
        if (!is_array($entries))
            return 0;

        $sum = 0;
        foreach($entries AS &$entry)
            $sum += $entry->getDuration();
        return $sum;
    }

    public function groupByCustomerAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedResult($request);
        } catch (\Exception $e) {
            $response = new Response($this->translate($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $customers = array();

        foreach($entries as $entry) {
            if (! is_object($entry->getCustomer()))
                continue;
            $customer = $entry->getCustomer()->getId();

            if(!isset($customers[$customer])) {
                $customers[$customer] = array(
                    'name' => $entry->getCustomer()->getName(),
                    'hours' => 0,
                    'quota' => 0
                );
            }

            $customers[$customer]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach($customers AS &$customer)
            $customer['quota'] = TimeHelper::formatQuota($customer['hours'], $sum);

        usort($customers, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($customers)));
    }

    public function groupByProjectAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedResult($request);
        } catch (\Exception $e) {
            $response = new Response($this->translate($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $projects = array();

        foreach ($entries as $entry) {
            if (! is_object($entry->getProject()))
                continue;
            $project = $entry->getProject()->getId();

            if(!isset($projects[$project])) {
                $projects[$project] = array(
                    'name' => $entry->getProject()->getName(),
                    'hours' => 0,
                    'quota' => 0
                );
            }

            $projects[$project]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach($projects AS &$project)
            $project['quota'] = TimeHelper::formatQuota($project['hours'], $sum);

        usort($projects, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($projects)));
    }


    public function groupByTicketAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedResult($request);
        } catch (\Exception $e) {
            $response = new Response($this->translate($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $tickets = array();

        foreach($entries as $entry) {
            $ticket = $entry->getTicket();

            if(!empty($ticket) && $ticket != '-'){
                if(!isset($tickets[$ticket])) {
                    $tickets[$ticket] = array(
                        'name' => $ticket,
                        'hours' => 0,
                        'quota' => 0
                    );
                }

                $tickets[$ticket]['hours'] += $entry->getDuration();
            }
        }

        $sum = $this->getCachedSum();
        foreach($tickets AS &$ticket)
            $ticket['quota'] = TimeHelper::formatQuota($ticket['hours'], $sum);

        usort($tickets, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($tickets)));
    }


    /**
     * Returns the data for the analysing chart "effort per employee".
     *
     * @return Response
     */
    public function groupByUserAction(Request $request)
    {
        #NRTECH-3720: pin the request to the current user id - make chart GDPR compliant
        $request->query->set('user', $this->_getUserId());

        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedResult($request);
        } catch (\Exception $e) {
            $response = new Response($this->translate($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $users = array();

        foreach($entries as $entry) {
            $user = $entry->getUser()->getId();

            if(!isset($users[$user])) {
                $users[$user] = array(
                    'name' => $entry->getUser()->getUsername(),
                    'hours' => 0,
                    'quota' => 0
                );
            }

            $users[$user]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach($users AS &$user)
            $user['quota'] = TimeHelper::formatQuota($user['hours'], $sum);

        usort($users, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($users)));
    }


    public function groupByWorktimeAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedResult($request);
        } catch (\Exception $e) {
            $response = new Response($this->translate($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $times = array();

        foreach($entries as $entry) {
            $day_r = $entry->getDay()->format('y-m-d');
            $day_l = $entry->getDay()->format('d.m.');

            if(!isset($times[$day_r])) {
                $times[$day_r] = array(
                    'name' => $day_r,
                    'day' => $day_l,
                    'hours' => 0,
                    'quota' => 0
                );
            }

            $times[$day_r]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach($times AS &$time)
            $time['quota'] = TimeHelper::formatQuota($time['hours'], $sum);

        usort($times, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData(array_reverse($times))));
    }

    public function groupByActivityAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedResult($request);
        } catch (\Exception $e) {
            $response = new Response($this->translate($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $activities = array();

        foreach($entries as $entry) {
            $activityId = $entry->getActivity()->getId();

            if(!isset($activities[$activityId])) {
                $activities[$activityId] = array(
                    'id' => $activityId,
                    'name' => $entry->getActivity()->getName(),
                    'hours' => 0
                );
            }

            $activities[$activityId]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach($activities AS &$activity)
            $activity['quota'] = TimeHelper::formatQuota($activity['hours'], $sum);

        usort($activities, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($activities)));
    }



    /**
     * @param Request $request
     * @return \Doctrine\ORM\QueryBuilder
     * @throws \Exception
     */
    private function getQuery(Request $request)
    {
        /* @var $repository EntryRepository */
        $repository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Entry');
        $query = $repository->createQueryBuilder('e');

        if (!strlen($request->query->get('customer'))
            && !strlen($request->query->get('project'))
            && !strlen($request->query->get('user'))
            && !strlen($request->query->get('ticket'))
            && (!strlen($request->query->get('month'))|| !strlen($request->query->get('year')))
            && (!strlen($request->query->get('team'))|| !strlen($request->query->get('year')))
        ) {
            throw new \Exception(
                $this->translate(
                    'You need to specify at least customer, project, ticket, user or month and year.'));
            return false;
        }

        /**
         * ??????
         *
         * Please, refactor!! May god help us all...
         */
        strlen($request->query->get('customer')) ?
            $query->andWhere('e.customer = :customer')
            ->setParameter('customer', (int) $request->query->get('customer'))
            : false;

        strlen($request->query->get('project')) ?
            $query->andWhere('e.project = :project')
            ->setParameter('project', (int) $request->query->get('project'))
            : false;

        if (strlen($request->query->get('user'))) {
            $query->andWhere('e.user = :user')
                ->setParameter('user', (int) $request->query->get('user'));
        }

        // filter by team
        if (strlen($request->query->get('team'))) {
            $query  ->leftJoin('e.user', 'u')
                ->leftJoin('u.teams', 't') //, 'ON', 'tu.user_id = u.id')
                ->andWhere('t.id = :team')
                ->setParameter('team', (int) $request->query->get('team'));
        }

        // filter interpretation by year or year and month
        if (strlen($request->query->get('year'))) {
            $year  = $request->query->get('year');
            $monthStart = '01';
            $monthEnd   = '12';

            if (strlen($request->query->get('month'))) {
                $monthStart = $request->query->get('month');
                $monthEnd = $monthStart;
            }

            $query->andWhere('e.day BETWEEN :start AND :end')
                ->setParameter('start', $year . '-' . $monthStart . '-01')
                ->setParameter('end',   $year . '-' . $monthEnd . '-31');
        }

        // filter by activity
        if (strlen($request->query->get('activity'))) {
            $query->andWhere('e.activity = :activity')
                ->setParameter('activity', (int) $request->query->get('activity'));
        }

        // filter by ticket
        if (strlen($request->query->get('ticket'))) {
            $query->andWhere('e.ticket LIKE :ticket')
                ->setParameter('ticket', $request->query->get('ticket'));
        }


        // filter by description
        if (strlen($request->query->get('description'))) {
            $query->andWhere('e.description LIKE :description')
                ->setParameter('description', '%' . $request->query->get('description') . '%');
        }

        // crap end
        return $query;
    }

    private function getConditions(Request $request)
    {
        $conditions = array();

        strlen($request->query->get('customer')) ?
            $conditions['customer'] = $request->query->get('customer')
            : false;

        strlen($request->query->get('project')) ?
            $conditions['project'] = $request->query->get('project')
            : false;

        strlen($request->query->get('team')) ?
            $conditions['team'] = $request->query->get('team')
            : false;

        strlen($request->query->get('user')) ?
            $conditions['user'] = $request->query->get('user')
            : false;

        strlen($request->query->get('description')) ?
            $conditions['description'] = $request->query->get('description')
            : false;

        strlen($request->query->get('activity')) ?
            $conditions['activity'] = $request->query->get('activity')
            : false;

        return $conditions;
    }

    private function normalizeData(array $data) {
        $normalized = array();

        foreach($data as $d) {
            $d['hours'] = $d['hours'] / 60;
            $normalized[] = $d;
        }

        return $normalized;
    }

}
