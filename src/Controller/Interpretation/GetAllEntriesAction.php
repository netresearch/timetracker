<?php
declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Controller\BaseController;
use App\Dto\InterpretationFiltersDto;
use App\Entity\Entry;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Response\Error;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;

final class GetAllEntriesAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/allEntries', name: 'interpretation_all_entries_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapQueryString] InterpretationFiltersDto $interpretationFiltersDto): ModelResponse|JsonResponse|Error
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        // Legacy *_id aliases are handled inside the DTO helper
        $project = $interpretationFiltersDto->project ?? $interpretationFiltersDto->project_id ?? 0;
        $customer = $interpretationFiltersDto->customer ?? $interpretationFiltersDto->customer_id ?? 0;
        $activity = $interpretationFiltersDto->activity ?? $interpretationFiltersDto->activity_id ?? 0;
        $maxResults = $interpretationFiltersDto->maxResults ?? 0;
        $page = $interpretationFiltersDto->page ?? 0;
        $datestart = $interpretationFiltersDto->datestart;
        $dateend = $interpretationFiltersDto->dateend;

        if ($page < 0) {
            $message = $this->translator->trans('page can not be negative.');

            return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
        }

        $maxResults = $maxResults > 0 ? $maxResults : 50;

        $searchArray = [
            'maxResults' => $maxResults,
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

        $entries = $paginator->getQuery()->getResult();
        $entryList = [];
        foreach ($entries as $entry) {
            $flatEntry = $entry->toArray();
            unset($flatEntry['class']);
            $flatEntry['date'] = $entry->getDay() ? $entry->getDay()->format('Y-m-d') : null;
            $flatEntry['user_id'] = $flatEntry['user'];
            $flatEntry['project_id'] = $flatEntry['project'];
            $flatEntry['customer_id'] = $flatEntry['customer'];
            $flatEntry['activity_id'] = $flatEntry['activity'];
            $flatEntry['worklog_id'] = $flatEntry['worklog'];
            unset($flatEntry['user'], $flatEntry['project'], $flatEntry['customer'], $flatEntry['activity'], $flatEntry['worklog']);
            $entryList[] = $flatEntry;
        }

        $route = $request->getUriForPath($request->getPathInfo()).'?';
        $query_params = [];
        if ($request->getQueryString()) {
            parse_str($request->getQueryString(), $query_params);
            unset($query_params['page']);
        }

        $total = $paginator->count();
        $links = ['links' => []];
        if ($total > 0) {
            $query_params['page'] = $page;
            $self = $route.http_build_query($query_params);

            $lastPage = ceil($total / $maxResults) - 1;
            $query_params['page'] = $lastPage;
            $last = $route.http_build_query($query_params);

            $query_params['page'] = min($page - 1, $lastPage);
            $prev = $page !== 0 ? $route.http_build_query($query_params) : null;

            $query_params['page'] = $page + 1;
            $next = $page < $lastPage ? $route.http_build_query($query_params) : null;

            $links = [
                'links' => [
                    'self' => $self,
                    'last' => $last,
                    'prev' => $prev,
                    'next' => $next,
                ],
            ];
        }

        // Always include self link. When there are no results, only self is present and others are null as per tests
        if ($links['links'] === []) {
            $query_params['page'] = $page;
            $self = $route.http_build_query($query_params);
            $links = [
                'links' => [
                    'self' => $self,
                    'last' => null,
                    'prev' => null,
                    'next' => null,
                ],
            ];
        }

        $entryList = array_merge($links, ['data' => $entryList]);

        return new JsonResponse($entryList);
    }
}


