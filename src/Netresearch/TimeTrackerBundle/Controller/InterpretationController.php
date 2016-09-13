<?php

namespace Netresearch\TimeTrackerBundle\Controller;

use Netresearch\TimeTrackerBundle\Entity\EntryRepository;
use Netresearch\TimeTrackerBundle\Helper\TimeHelper;
use Symfony\Component\HttpFoundation\Response;
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
            $entries = $this->getEntries($request, 50);
        } catch (\Exception $e) {
            $response = new Response($this->translate($e->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

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

    private function getCachedEntries(Request $request)
    {
        if (null != $this->cache)
            return $this->cache;

        $this->cache = $this->getEntries($request);
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
            $entries = $this->getCachedEntries($request);
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
            $entries = $this->getCachedEntries($request);
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
            $entries = $this->getCachedEntries($request);
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

    public function groupByUserAction(Request $request)
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
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
            $entries = $this->getCachedEntries($request);
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
            $entries = $this->getCachedEntries($request);
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
     * Get entries by request parameter
     *
     * @param Request $request
     * @param integer $maxResults
     * @return array
     * @throws \Exception
     */
    private function getEntries(Request $request, $maxResults = null)
    {
        $arParams = [
            'customer'          => $this->evalParam($request, 'customer'),
            'project'           => $this->evalParam($request, 'project'),
            'user'              => $this->evalParam($request, 'user'),
            'activity'          => $this->evalParam($request, 'activity'),
            'team'              => $this->evalParam($request, 'team'),
            'ticket'            => $this->evalParam($request, 'ticket'),
            'month'             => $this->evalParam($request, 'month'),
            'year'              => $this->evalParam($request, 'year'),
            'description'       => $this->evalParam($request, 'description'),
            'visibility_user'   => ($this->_isDEV($request)? $this->_getUserId($request) : null),
            'maxResults'        => $maxResults,
        ];

        if (!$arParams['customer']
            && !$arParams['project']
            && !$arParams['user']
            && !$arParams['ticket']
            && (!$arParams['month'] || !$arParams['year'])
            && (!$arParams['team'] || !$arParams['year'])
        ) {
            throw new \Exception(
                $this->translate('You need to specify at least customer, project, ticket, user or month and year.')
            );
        }

        /* @var $repository EntryRepository */
        $repository = $this->getDoctrine()->getRepository('NetresearchTimeTrackerBundle:Entry');
        return $repository->findByFilterArray($arParams);
    }

    private function evalParam(Request $request, $param)
    {
        $param = $request->query->get($param);
        if ($param && !empty($param)) {
            return $param;
        }
        return null;
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
