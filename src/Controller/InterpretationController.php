<?php

namespace App\Controller;

use App\Entity\Entry;
use App\Response\Error;
use App\Entity\User;
use App\Helper\TimeHelper;
use App\Model\JsonResponse;
use App\Model\Response;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;

class InterpretationController extends BaseController
{
    /**
     * @var Entry[]
     */
    private $cache;

    public function sortByName(array $a, array $b): int
    {
        return strcmp((string) $b['name'], (string) $a['name']);
    }

    public function getLastEntriesAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getEntries($request, 50);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $sum = $this->calculateSum($entries);
        $entryList = [];
        foreach ($entries as $entry) {
            $flatEntry = $entry->toArray();
            $flatEntry['quota'] = TimeHelper::formatQuota($flatEntry['duration'], $sum);
            $flatEntry['duration'] = TimeHelper::formatDuration($flatEntry['duration']);
            $entryList[] = ['entry' => $flatEntry];
        }

        return new JsonResponse($entryList);
    }

    /**
     * @return array|null
     * @throws \Exception
     */
    private function getCachedEntries(Request $request)
    {
        if (null != $this->cache) {
            return $this->cache;
        }

        $this->cache = $this->getEntries($request);
        return $this->cache;
    }

    /**
     * @return int
     */
    private function getCachedSum(): int|float
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

    private function calculateSum(&$entries): int|float
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

    public function groupByCustomerAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $customers = [];

        foreach ($entries as $entry) {
            if (! is_object($entry->getCustomer())) {
                continue;
            }

            $customer = $entry->getCustomer()->getId();

            if (!isset($customers[$customer])) {
                $customers[$customer] = [
                    'id'  => $customer,
                    'name'  => $entry->getCustomer()->getName(),
                    'hours' => 0,
                    'quota' => 0,
                ];
            }

            $customers[$customer]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach ($customers as &$customer) {
            $customer['quota'] = TimeHelper::formatQuota($customer['hours'], $sum);
        }

        usort($customers, $this->sortByName(...));
        return new JsonResponse($this->normalizeData($customers));
    }

    public function groupByProjectAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $projects = [];

        foreach ($entries as $entry) {
            if (! is_object($entry->getProject())) {
                continue;
            }

            $project = $entry->getProject()->getId();

            if (!isset($projects[$project])) {
                $projects[$project] = [
                    'id'  => $project,
                    'name'  => $entry->getProject()->getName(),
                    'hours' => 0,
                    'quota' => 0,
                ];
            }

            $projects[$project]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach ($projects as &$project) {
            $project['quota'] = TimeHelper::formatQuota($project['hours'], $sum);
        }

        usort($projects, $this->sortByName(...));
        return new JsonResponse($this->normalizeData($projects));
    }


    public function groupByTicketAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $tickets = [];

        foreach ($entries as $entry) {
            $ticket = $entry->getTicket();

            if (!empty($ticket) && $ticket != '-') {
                if (!isset($tickets[$ticket])) {
                    $tickets[$ticket] = [
                        'id'  => $entry->getId(),
                        'name'  => $ticket,
                        'hours' => 0,
                        'quota' => 0,
                    ];
                }

                $tickets[$ticket]['hours'] += $entry->getDuration();
            }
        }

        $sum = $this->getCachedSum();
        foreach ($tickets as &$ticket) {
            $ticket['quota'] = TimeHelper::formatQuota($ticket['hours'], $sum);
        }

        usort($tickets, $this->sortByName(...));
        return new JsonResponse($this->normalizeData($tickets));
    }


    /**
     * Returns the data for the analysing chart "effort per employee".
     */
    public function groupByUserAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        #NRTECH-3720: pin the request to the current user id - make chart GDPR compliant
        $request->query->set('user', $this->getUserId($request));

        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $users = [];

        foreach ($entries as $entry) {
            $user = $entry->getUser()->getId();

            if (!isset($users[$user])) {
                $users[$user] = [
                    'id'  => $user,
                    'name'  => $entry->getUser()->getUsername(),
                    'hours' => 0,
                    'quota' => 0,
                ];
            }

            $users[$user]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach ($users as $user) {
            $user['quota'] = TimeHelper::formatQuota($user['hours'], $sum);
        }

        usort($users, $this->sortByName(...));
        return new JsonResponse($this->normalizeData($users));
    }


    /**
     * Returns booked times grouped by day.
     */
    public function groupByWorktimeAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $times = [];

        foreach ($entries as $entry) {
            $day_r = $entry->getDay()->format('y-m-d');

            if (!isset($times[$day_r])) {
                $times[$day_r] = [
                    'name'  => $day_r,
                    'day'   => $entry->getDay()->format('d.m.'),
                    'hours' => 0,
                    'quota' => 0,
                ];
            }

            $times[$day_r]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach ($times as &$time) {
            $time['quota'] = TimeHelper::formatQuota($time['hours'], $sum);
        }

        usort($times, $this->sortByName(...));
        return new JsonResponse($this->normalizeData(array_reverse($times)));
    }

    public function groupByActivityAction(Request $request): \App\Model\Response|\App\Model\JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(406);
            return $response;
        }

        $activities = [];

        foreach ($entries as $entry) {
            $activityId = $entry->getActivity()->getId();

            if (!isset($activities[$activityId])) {
                $activities[$activityId] = [
                    'id'    => $activityId,
                    'name'  => $entry->getActivity()->getName(),
                    'hours' => 0,
                ];
            }

            $activities[$activityId]['hours'] += $entry->getDuration();
        }

        $sum = $this->getCachedSum();
        foreach ($activities as &$activity) {
            $activity['quota'] = TimeHelper::formatQuota($activity['hours'], $sum);
        }

        usort($activities, $this->sortByName(...));
        return new JsonResponse($this->normalizeData($activities));
    }


    /**
     * Get entries by request parameter
     *
     * @param integer $maxResults
     * @return Entry[]
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
            'description'       => $this->evalParam($request, 'description'),
            'visibility_user'   => ($this->isDEV($request)? $this->getUserId($request) : null),
            'maxResults'        => $maxResults,
            'datestart'         => $this->evalParam($request, 'datestart'),
            'dateend'           => $this->evalParam($request, 'dateend'),
        ];

        $year = $this->evalParam($request, 'year');
        if (null !== $year) {
            $month = $this->evalParam($request, 'month');
            if (null !== $month) {
                // first day of month
                $datestart = $year . '-' . $month . '-01';

                // last day of month
                $dateend = \DateTime::createFromFormat('Y-m-d', $datestart);
                $dateend->add(new \DateInterval('P1M'));
                // go back 1 day, to set date from first day of next month back to last day of last month
                // e.g. 2019-05-01 -> 2019-04-30
                $dateend->sub(new \DateInterval('P1D'));
            } else {
                // first day of year
                $datestart = $year . '-01-01';

                // last day of year
                $dateend = \DateTime::createFromFormat('Y-m-d', $datestart);
                $dateend->add(new \DateInterval('P1Y'));
                // go back 1 day, to set date from first day of next year back to last day of last year
                // e.g. 2019-01-01 -> 2018-12-31
                $dateend->sub(new \DateInterval('P1D'));
            }

            $arParams['datestart'] = $datestart;
            $arParams['dateend'] = $dateend->format('Y-m-d');
        }

        if (!$arParams['customer']
            && !$arParams['project']
            && !$arParams['user']
            && !$arParams['ticket']
        ) {
            throw new \Exception(
                $this->translate('You need to specify at least customer, project, ticket, user or month and year.')
            );
        }

        /** @var \App\Repository\EntryRepository $objectRepository*/
        $objectRepository = $this->getDoctrine()->getRepository(\App\Entity\Entry::class);
        return $objectRepository->findByFilterArray($arParams);
    }

    private function evalParam(Request $request, string $param)
    {
        $param = $request->query->get($param);
        if ($param && !empty($param)) {
            return $param;
        }

        return null;
    }

    /**
     * @return mixed[]
     */
    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $d) {
            $d['hours'] /= 60;
            $normalized[] = $d;
        }

        return $normalized;
    }

    /**
     * Retrieves filtered time tracker entries based on request parameters.
     * Applies pagination.
     *
     * @return \App\Model\JsonResponse|Error
     * @throws \Exception
     */
    public function getAllEntriesAction(Request $request): \App\Model\Response|\App\Response\Error|\App\Model\JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $project = (int) $request->get('project_id');
        $datestart = $request->get('datestart');
        $dateend = $request->get('dateend');
        $customer = (int) $request->get('customer_id');
        $activity = (int) $request->get('activity_id');
        $maxResults = (int) $request->get('maxResults');
        $page = (int) $request->get('page');

        //prepare data
        if ($page < 0) {
            $message = $this->translator->trans('page can not be negative.');
            return new Error($message, 400);
        }

        $maxResults = $maxResults > 0 ? $maxResults : 50;    //cant be lower than 0

        $searchArray = [
            'maxResults' => $maxResults,   //default 50
            'page' => $page
        ];
        if (($activity ?? null) !== 0) {
            $searchArray['activity'] = $activity;
        }

        if (($project ?? null) !== 0) {
            $searchArray['project'] = $project;
        }

        if ($datestart ?? null) {
            $searchArray['datestart'] = $datestart;
        }

        if ($dateend ?? null) {
            $searchArray['dateend'] = $dateend;
        }

        if (($customer ?? null) !== 0) {
            $searchArray['customer'] = $customer;
        }

        /** @var \App\Repository\EntryRepository $objectRepository*/
        $objectRepository = $this->getDoctrine()->getRepository(\App\Entity\Entry::class);
        try {
            $paginator = new Paginator($objectRepository->queryByFilterArray($searchArray));
        } catch (\Exception $exception) {
            return new Error($this->translate($exception->getMessage()), 406);
        }

        // get data
        $entries = $paginator->getQuery()->getResult();
        $entryList = [];
        foreach ($entries as $entry) {
            $flatEntry = $entry->toArray();
            unset($flatEntry['class']);
            $flatEntry['date'] = $entry->getDay() ? $entry->getDay()->format('Y-m-d') : null;

            // add id suffix to if parameter
            $flatEntry['user_id'] = $flatEntry['user'];
            $flatEntry['project_id'] = $flatEntry['project'];
            $flatEntry['customer_id'] = $flatEntry['customer'];
            $flatEntry['activity_id'] = $flatEntry['activity'];
            $flatEntry['worklog_id'] = $flatEntry['worklog'];
            // unset old keys
            unset($flatEntry['user']);
            unset($flatEntry['project']);
            unset($flatEntry['customer']);
            unset($flatEntry['activity']);
            unset($flatEntry['worklog']);

            // build result
            $entryList[] = $flatEntry;
        }

        // build url
        $route = $request->getUriForPath($request->getPathInfo()) . '?';

        $query_params = [];
        if ($request->getQueryString()) {
            parse_str($request->getQueryString(), $query_params);
            unset($query_params['page']);
        }

        // negative firstResult are interpreted as 0
        $total = $paginator->count();

        //self
        $query_params['page'] = $page;
        $self = $route . http_build_query($query_params);

        // returns null for empty Paginator, else returns last page for given $maxResults
        $lastPage = ceil($total / $maxResults) - 1;
        $query_params['page'] = $lastPage;
        $last = $total
            ? $route . http_build_query($query_params)
            : null;

        // returns the last previous page with data, or null if you are on page 0 or there is no data
        $query_params['page'] = min($page - 1, $lastPage);
        $prev = $page && $total
            ? $route . http_build_query($query_params)
            : null;

        //null when query would return empty data
        $query_params['page'] = $page + 1;
        $next = $page < $lastPage
            ? $route . http_build_query($query_params)
            : null;

        $links = [
            'links' => [
                'self' => $self,
                'last' => $last,
                'prev' => $prev,
                'next' => $next,
            ],
        ];
        $entryList = array_merge($links, ['data' => $entryList]);
        return new JsonResponse($entryList);
    }
}
