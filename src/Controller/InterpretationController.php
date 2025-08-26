<?php

namespace App\Controller;

use App\Entity\Entry;
use App\Entity\User;
use App\Dto\InterpretationFiltersDto;
use App\Service\Util\TimeCalculationService;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;

class InterpretationController extends BaseController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }
    /**
     * @var Entry[]|null
     */
    private ?array $cache = null;

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @psalm-return int<-1, 1>
     */
    public function sortByName(array $a, array $b): int
    {
        return strcmp((string) $b['name'], (string) $a['name']);
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/entries', name: 'interpretation_entries_attr', methods: ['GET'])]
    public function getLastEntries(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getEntries($request, 50);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $sum = $this->calculateSum($entries);
        $entryList = [];
        foreach ($entries as $entry) {
            $flatEntry = $entry->toArray();
            $flatEntry['quota'] = $this->timeCalculationService->formatQuota($flatEntry['duration'], $sum);
            $flatEntry['duration'] = $this->timeCalculationService->formatDuration($flatEntry['duration']);
            $entryList[] = ['entry' => $flatEntry];
        }

        return new JsonResponse($entryList);
    }

    /**
     * @throws \Exception
     *
     * @return Entry[]
     *
     * @psalm-return array<Entry>
     */
    private function getCachedEntries(Request $request): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $this->cache = $this->getEntries($request);

        return $this->cache;
    }

    /**
     * @psalm-return int<min, max>
     */
    private function getCachedSum(): int
    {
        if (null === $this->cache) {
            return 0;
        }

        $sum = 0;
        foreach ($this->cache as $entry) {
            $sum += $entry->getDuration();
        }

        return $sum;
    }

    /**
     * @param Entry[] $entries
     *
     * @psalm-param array<Entry> $entries
     *
     * @psalm-return int<min, max>
     */
    private function calculateSum(array &$entries): int
    {
        $sum = 0;
        foreach ($entries as $entry) {
            $sum += $entry->getDuration();
        }

        return $sum;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/customer', name: 'interpretation_customer_attr', methods: ['GET'])]
    public function groupByCustomer(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $customers = [];

        foreach ($entries as $entry) {
            if (!is_object($entry->getCustomer())) {
                continue;
            }

            $customer = $entry->getCustomer()->getId();

            if (!isset($customers[$customer])) {
                $customers[$customer] = [
                    'id' => $customer,
                    'name' => $entry->getCustomer()->getName(),
                    'hours' => 0,
                    'quota' => 0,
                ];
            }

            $customers[$customer]['hours'] += $entry->getDuration() / 60;
        }

        $sum = $this->getCachedSum();
        foreach ($customers as &$customer) {
            $customer['quota'] = $this->timeCalculationService->formatQuota($customer['hours'], $sum);
        }

        /* @var array<int, array{id:int,name:string,hours:int,quota?:string}> $customers */
        usort($customers, $this->sortByName(...));

        return new JsonResponse($this->normalizeData($customers));
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/project', name: 'interpretation_project_attr', methods: ['GET'])]
    public function groupByProject(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $projects = [];

        foreach ($entries as $entry) {
            if (!is_object($entry->getProject())) {
                continue;
            }

            $project = $entry->getProject()->getId();

            if (!isset($projects[$project])) {
                $projects[$project] = [
                    'id' => $project,
                    'name' => $entry->getProject()->getName(),
                    'hours' => 0,
                    'quota' => 0,
                ];
            }

            $projects[$project]['hours'] += $entry->getDuration() / 60;
        }

        $sum = $this->getCachedSum();
        foreach ($projects as &$project) {
            $project['quota'] = $this->timeCalculationService->formatQuota($project['hours'], $sum);
        }

        /* @var array<int, array{id:int,name:string,hours:int,quota?:string}> $projects */
        usort($projects, $this->sortByName(...));

        return new JsonResponse($this->normalizeData($projects));
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/ticket', name: 'interpretation_ticket_attr', methods: ['GET'])]
    public function groupByTicket(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $tickets = [];

        foreach ($entries as $entry) {
            $ticket = $entry->getTicket();

            if ('' !== $ticket && '-' !== $ticket) {
                if (!isset($tickets[$ticket])) {
                    $tickets[$ticket] = [
                        'id' => $entry->getId(),
                        'name' => $ticket,
                        'hours' => 0,
                        'quota' => 0,
                    ];
                }

                $tickets[$ticket]['hours'] += $entry->getDuration() / 60;
            }
        }

        $sum = $this->getCachedSum();
        foreach ($tickets as &$ticket) {
            $ticket['quota'] = $this->timeCalculationService->formatQuota($ticket['hours'], $sum);
        }

        /* @var array<int, array{id:int,name:string,hours:int,quota?:string}> $tickets */
        usort($tickets, $this->sortByName(...));

        return new JsonResponse($this->normalizeData($tickets));
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/user', name: 'interpretation_user_attr', methods: ['GET'])]
    public function groupByUser(Request $request): Response|JsonResponse
    {
        // NRTECH-3720: pin the request to the current user id - make chart GDPR compliant
        $request->query->set('user', $this->getUserId($request));

        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $users = [];

        foreach ($entries as $entry) {
            $u = $entry->getUser();
            if (!$u instanceof User) {
                continue;
            }
            $user = $u->getId();

            if (!isset($users[$user])) {
                $users[$user] = [
                    'id' => $user,
                    'name' => (string) $u->getUsername(),
                    'hours' => 0,
                    'quota' => 0,
                ];
            }

            $users[$user]['hours'] += $entry->getDuration() / 60;
        }

        $sum = $this->getCachedSum();
        foreach ($users as &$user) {
            $user['quota'] = $this->timeCalculationService->formatQuota($user['hours'], $sum);
        }

        /* @var array<int, array{id:int,name:string,hours:int,quota?:string}> $users */
        usort($users, $this->sortByName(...));

        return new JsonResponse($this->normalizeData($users));
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/time', name: 'interpretation_time_attr', methods: ['GET'])]
    public function groupByWorktime(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $times = [];

        foreach ($entries as $entry) {
            $day = $entry->getDay();
            if (!$day instanceof \DateTimeInterface) {
                continue;
            }
            $day_r = $day->format('y-m-d');

            if (!isset($times[$day_r])) {
                $times[$day_r] = [
                    'id' => null,
                    'name' => $day_r,
                    'day' => $day->format('d.m.'),
                    'hours' => 0,
                    'minutes' => 0,
                    'quota' => 0,
                ];
            }

            $times[$day_r]['minutes'] += $entry->getDuration();
        }

        // convert minutes to hours preserving fractional part
        $totalMinutes = 0.0;
        foreach ($times as $t) {
            $totalMinutes += (float) $t['minutes'];
        }

        foreach ($times as &$time) {
            $minutes = (float) $time['minutes'];
            $time['hours'] = $minutes / 60.0;
            unset($time['minutes']);
            $time['quota'] = $this->timeCalculationService->formatQuota($minutes, $totalMinutes);
        }

        /* @var array<int, array{name:string,day:string,hours:int,quota?:string}> $times */
        usort($times, $this->sortByName(...));

        $prepared = array_map(
            /**
             * @return (float|string|null)[]
             *
             * @psalm-return array{id: null, name: string, day: string, hours: float, quota: '0'}
             */
            static fn(array $t): array => [
                'id' => $t['id'],
                'name' => $t['name'],
                'day' => $t['day'],
                'hours' => (float) $t['hours'],
                'quota' => (string) $t['quota'],
            ],
            $times
        );

        return new JsonResponse($this->normalizeData(array_reverse($prepared)));
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/activity', name: 'interpretation_activity_attr', methods: ['GET'])]
    public function groupByActivity(Request $request): Response|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getCachedEntries($request);
        } catch (\Exception $exception) {
            $response = new Response($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $activities = [];

        foreach ($entries as $entry) {
            $activityObj = $entry->getActivity();
            if (!$activityObj instanceof \App\Entity\Activity) {
                continue;
            }
            $activityId = $activityObj->getId();

            if (!isset($activities[$activityId])) {
                $activities[$activityId] = [
                    'id' => $activityId,
                    'name' => $activityObj->getName(),
                    'hours' => 0,
                ];
            }

            $activities[$activityId]['hours'] += $entry->getDuration() / 60;
        }

        $activitiesTotalHours = 0.0;
        foreach ($activities as $a) {
            $activitiesTotalHours += (float) $a['hours'];
        }

        foreach ($activities as &$activity) {
            $activity['quota'] = $this->timeCalculationService->formatQuota($activity['hours'], $activitiesTotalHours);
        }

        /* @var array<int, array{id:int,name:string,hours:int,quota?:string}> $activities */
        usort($activities, $this->sortByName(...));

        return new JsonResponse($this->normalizeData($activities));
    }

    /**
     * Get entries by request parameter.
     *
     * @throws \Exception
     *
     * @return Entry[]
     *
     * @psalm-return array<int, Entry>
     */
    private function getEntries(Request $request, ?int $maxResults = null): array
    {
        $filters = InterpretationFiltersDto::fromRequest($request);
        $arParams = $filters->toFilterArray($this->isDEV($request) ? $this->getUserId($request) : null, $maxResults);

        $year = $filters->year;
        if (null !== $year) {
            $month = $filters->month;
            if (null !== $month) {
                // first day of month
                $datestart = $year.'-'.$month.'-01';

                // last day of month
                $dateend = \DateTime::createFromFormat('Y-m-d', $datestart);
                if (false === $dateend) {
                    throw new \Exception('Invalid date');
                }

                $dateend->add(new \DateInterval('P1M'));
                // go back 1 day, to set date from first day of next month back to last day of last month
                // e.g. 2019-05-01 -> 2019-04-30
                $dateend->sub(new \DateInterval('P1D'));
            } else {
                // first day of year
                $datestart = $year.'-01-01';

                // last day of year
                $dateend = \DateTime::createFromFormat('Y-m-d', $datestart);
                if (false === $dateend) {
                    throw new \Exception('Invalid date');
                }

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
            throw new \Exception($this->translate('You need to specify at least customer, project, ticket, user or month and year.'));
        }

        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);

        return $objectRepository->findByFilterArray($arParams);
    }

    private function evalParam(Request $request, string $param): ?string
    {
        $value = $request->query->get($param);
        if (is_scalar($value)) {
            $string = $value;

            return '' !== $string ? $string : null;
        }

        return null;
    }

    /**
     * @param array<int, array{id:int|null, name:string|null, day?:string, hours:int|float, quota?:int|string}> $data
     *
     * @return (float|int|string|null)[][]
     *
     * @psalm-return list{0?: array{id: int|null, name: string, day: null|string, hours: float, quota: string},...}
     */
    private function normalizeData(array $data): array
    {
        $normalized = [];

        foreach ($data as $d) {
            $hours = (float) $d['hours'];
            $normalized[] = [
                'id' => $d['id'] ?? null,
                'name' => $d['name'] ?? '',
                'day' => $d['day'] ?? null,
                'hours' => $hours,
                'quota' => isset($d['quota']) ? (string) $d['quota'] : '0%',
            ];
        }

        return $normalized;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/allEntries', name: 'interpretation_all_entries_attr', methods: ['POST'])]
    public function getAllEntries(Request $request): Response|Error|JsonResponse
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        $project = (int) $request->query->get('project_id');
        $datestart = $request->query->get('datestart');
        $dateend = $request->query->get('dateend');
        $customer = (int) $request->query->get('customer_id');
        $activity = (int) $request->query->get('activity_id');
        $maxResults = (int) $request->query->get('maxResults');
        $page = (int) $request->query->get('page');

        // prepare data
        if ($page < 0) {
            $message = $this->translator->trans('page can not be negative.');

            return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
        }

        $maxResults = $maxResults > 0 ? $maxResults : 50;    // cant be lower than 0

        $searchArray = [
            'maxResults' => $maxResults,   // default 50
            'page' => $page,
        ];
        if (0 !== $activity) {
            $searchArray['activity'] = $activity;
        }

        if (0 !== $project) {
            $searchArray['project'] = $project;
        }

        if (is_string($datestart) && '' !== $datestart) {
            $searchArray['datestart'] = $datestart;
        }

        if (is_string($dateend) && '' !== $dateend) {
            $searchArray['dateend'] = $dateend;
        }

        if (0 !== $customer) {
            $searchArray['customer'] = $customer;
        }

        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        try {
            $query = $objectRepository->queryByFilterArray($searchArray);
            if (!$query instanceof \Doctrine\ORM\Query) {
                $query = $query->getQuery();
            }
            $paginator = new Paginator($query);
        } catch (\Exception $exception) {
            return new Error($this->translate($exception->getMessage()), \Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
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
        $route = $request->getUriForPath($request->getPathInfo()).'?';

        $query_params = [];
        if ($request->getQueryString()) {
            parse_str($request->getQueryString(), $query_params);
            unset($query_params['page']);
        }

        // negative firstResult are interpreted as 0
        $total = $paginator->count();

        // self
        $query_params['page'] = $page;
        $self = $route.http_build_query($query_params);

        // returns null for empty Paginator, else returns last page for given $maxResults
        $lastPage = ceil($total / $maxResults) - 1;
        $query_params['page'] = $lastPage;
        $last = $total
            ? $route.http_build_query($query_params)
            : null;

        // returns the last previous page with data, or null if you are on page 0 or there is no data
        $query_params['page'] = min($page - 1, $lastPage);
        $prev = $page && $total
            ? $route.http_build_query($query_params)
            : null;

        // null when query would return empty data
        $query_params['page'] = $page + 1;
        $next = $page < $lastPage
            ? $route.http_build_query($query_params)
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
