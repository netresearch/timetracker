<?php

namespace App\Controller;

use Exception;
use DateTime;
use DateInterval;
use App\Entity\Entry;
use App\Helper\TimeHelper;
use App\Model\Response;
use GuzzleHttp\Exception\BadResponseException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\LazyResponseException;

class InterpretationController extends BaseController
{
    /**
     * @var Entry[]
     */
    private ?array $cache = null;

    public function sortByName($a, $b)
    {
        return strcmp($b['name'], $a['name']);
    }

    #[Route(path: '/interpretation/entries', name: 'interpretation_entries')]
    public function getLastEntriesAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getEntries(50);
        } catch (Exception $e) {
            $response = new Response($this->t($e->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $sum = $this->calculateSum($entries);
        $entryList = array();
        foreach ($entries as $entry) {
            $flatEntry = $entry->toArray();
            $flatEntry['duration'] = TimeHelper::formatDuration($flatEntry['duration']);
            $flatEntry['quota'] = TimeHelper::formatQuota($flatEntry['duration'], $sum);
            $entryList[] = array('entry' => $flatEntry);
        }
        return new Response(json_encode($entryList, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array|null
     * @throws Exception
     */
    private function getCachedEntries()
    {
        if (null != $this->cache) {
            return $this->cache;
        }

        $this->cache = $this->getEntries();
        return $this->cache;
    }

    /**
     * @return int
     */
    private function getCachedSum()
    {
        if (null == $this->cache) {
            return 0;
        }

        $sum = 0;
        foreach ($this->cache as $entry) {
            $sum += $entry->getDuration();
        }

        return $sum;
    }

    private function calculateSum(&$entries)
    {
        if (!is_array($entries)) {
            return 0;
        }

        $sum = 0;
        foreach ($entries as $entry) {
            $sum += $entry->getDuration();
        }

        return $sum;
    }

    #[Route(path: '/interpretation/customer', name: 'interpretation_customer')]
    public function groupByCustomerAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries();
        } catch (Exception $e) {
            $response = new Response($this->t($e->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $customers = array();

        foreach($entries as $entry) {
            if (! is_object($entry->getCustomer()))
                continue;
            $customer = $entry->getCustomer()->getId();

            if(!isset($customers[$customer])) {
                $customers[$customer] = array(
                    'name'  => $entry->getCustomer()->getName(),
                    'hours' => 0,
                    'quota' => 0,
                );
            }

            $customers[$customer]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach($customers AS &$customer)
            $customer['quota'] = TimeHelper::formatQuota($customer['hours'], $sum);

        usort($customers, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($customers), JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/interpretation/project', name: 'interpretation_project')]
    public function groupByProjectAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries();
        } catch (Exception $e) {
            $response = new Response($this->t($e->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $projects = array();

        foreach ($entries as $entry) {
            if (! is_object($entry->getProject()))
                continue;
            $project = $entry->getProject()->getId();

            if(!isset($projects[$project])) {
                $projects[$project] = array(
                    'name'  => $entry->getProject()->getName(),
                    'hours' => 0,
                    'quota' => 0,
                );
            }

            $projects[$project]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach($projects AS &$project)
            $project['quota'] = TimeHelper::formatQuota($project['hours'], $sum);

        usort($projects, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($projects), JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/interpretation/ticket', name: 'interpretation_ticket')]
    public function groupByTicketAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries();
        } catch (Exception $e) {
            $response = new Response($this->t($e->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $tickets = array();

        foreach($entries as $entry) {
            $ticket = $entry->getTicket();

            if(!empty($ticket) && $ticket != '-'){
                if(!isset($tickets[$ticket])) {
                    $tickets[$ticket] = array(
                        'name'  => $ticket,
                        'hours' => 0,
                        'quota' => 0,
                    );
                }

                $tickets[$ticket]['hours'] += $entry->getDuration();
            }
        }

        $sum = $this->getCachedSum();
        foreach($tickets AS &$ticket)
            $ticket['quota'] = TimeHelper::formatQuota($ticket['hours'], $sum);

        usort($tickets, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($tickets), JSON_THROW_ON_ERROR));
    }


    /**
     * Returns the data for the analysing chart "effort per employee".
     */
    #[Route(path: '/interpretation/user', name: 'interpretation_user')]
    public function groupByUserAction(): Response
    {
        #NRTECH-3720: pin the request to the current user id - make chart GDPR compliant
        $this->request->query->set('user', $this->getUserId());

        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries();
        } catch (Exception $e) {
            $response = new Response($this->t($e->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $users = array();

        foreach ($entries as $entry) {
            $user = $entry->getUser()->getId();

            if (!isset($users[$user])) {
                $users[$user] = array(
                    'name'  => $entry->getUser()->getUsername(),
                    'hours' => 0,
                    'quota' => 0,
                );
            }

            $users[$user]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach ($users as $user) {
            $user['quota'] = TimeHelper::formatQuota($user['hours'], $sum);
        }

        usort($users, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($users), JSON_THROW_ON_ERROR));
    }


    /**
     * Returns booked times grouped by day.
     */
    #[Route(path: '/interpretation/time', name: 'interpretation_time')]
    public function groupByWorktimeAction(): Response
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries();
        } catch (Exception $e) {
            $response = new Response($this->t($e->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $times = array();

        foreach ($entries as $entry) {
            $day_r = $entry->getDay()->format('y-m-d');

            if (!isset($times[$day_r])) {
                $times[$day_r] = array(
                    'name'  => $day_r,
                    'day'   => $entry->getDay()->format('d.m.'),
                    'hours' => 0,
                    'quota' => 0,
                );
            }

            $times[$day_r]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach ($times as &$time) {
            $time['quota'] = TimeHelper::formatQuota($time['hours'], $sum);
        }

        usort($times, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData(array_reverse($times)), JSON_THROW_ON_ERROR));
    }

    #[Route(path: '/interpretation/activity', name: 'interpretation_activity')]
    public function groupByActivityAction()
    {
        if (!$this->checkLogin()) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries();
        } catch (Exception $e) {
            $response = new Response($this->t($e->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $activities = array();

        foreach($entries as $entry) {
            $activityId = $entry->getActivity()->getId();

            if(!isset($activities[$activityId])) {
                $activities[$activityId] = array(
                    'id'    => $activityId,
                    'name'  => $entry->getActivity()->getName(),
                    'hours' => 0,
                );
            }

            $activities[$activityId]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach($activities AS &$activity)
            $activity['quota'] = TimeHelper::formatQuota($activity['hours'], $sum);

        usort($activities, array($this, 'sortByName'));
        return new Response(json_encode($this->normalizeData($activities), JSON_THROW_ON_ERROR));
    }


    /**
     * Get entries by request parameter
     *
     * @param integer $maxResults
     * @return Entry[]
     * @throws Exception
     */
    private function getEntries($maxResults = null)
    {
        $arParams = [
            'customer'          => $this->evalParam('customer'),
            'project'           => $this->evalParam('project'),
            'user'              => $this->evalParam('user'),
            'activity'          => $this->evalParam('activity'),
            'team'              => $this->evalParam('team'),
            'ticket'            => $this->evalParam('ticket'),
            'description'       => $this->evalParam('description'),
            'visibility_user'   => ($this->isDEV()? $this->getUserId() : null),
            'maxResults'        => $maxResults,
            'datestart'         => $this->evalParam('datestart'),
            'dateend'           => $this->evalParam('dateend'),
        ];

        $year = $this->evalParam('year');
        if (null !== $year) {
            $month = $this->evalParam('month');
            if (null !== $month) {
                // first day of month
                $datestart = $year . '-' . $month . '-01';

                // last day of month
                $dateend = DateTime::createFromFormat('Y-m-d', $datestart);
                $dateend->add(new DateInterval('P1M'));
                // go back 1 day, to set date from first day of next month back to last day of last month
                // e.g. 2019-05-01 -> 2019-04-30
                $dateend->sub(new DateInterval('P1D'));
            } else {
                // first day of year
                $datestart = $year . '-01-01';

                // last day of year
                $dateend = DateTime::createFromFormat('Y-m-d', $datestart);
                $dateend->add(new DateInterval('P1Y'));
                // go back 1 day, to set date from first day of next year back to last day of last year
                // e.g. 2019-01-01 -> 2018-12-31
                $dateend->sub(new DateInterval('P1D'));
            }

            $arParams['datestart'] = $datestart;
            $arParams['dateend'] = $dateend->format('Y-m-d');
        }

        if (!$arParams['customer']
            && !$arParams['project']
            && !$arParams['user']
            && !$arParams['ticket']
        ) {
            throw new Exception(
                $this->t('You need to specify at least customer, project, ticket, user or month and year.')
            );
        }

        /* @var $repository \App\Repository\EntryRepository */
        $repository = $this->doctrine->getRepository('App:Entry');
        return $repository->findByFilterArray($arParams);
    }

    private function evalParam($param)
    {
        $param = $this->request->query->get($param);
        if ($param && !empty($param)) {
            return $param;
        }
        return null;
    }

    private function normalizeData(array $data)
    {
        $normalized = array();

        foreach($data as $d) {
            $d['hours'] = $d['hours'] / 60;
            $normalized[] = $d;
        }

        return $normalized;
    }

}
