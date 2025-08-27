<?php
declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Controller\BaseController;
use App\Dto\InterpretationFiltersDto;
use App\Entity\Entry;

abstract class BaseInterpretationController extends BaseController
{
    /**
     * @param Entry[] $entries
     * @psalm-param array<Entry> $entries
     * @psalm-return int<min, max>
     */
    protected function calculateSum(array &$entries): int
    {
        $sum = 0;
        foreach ($entries as $entry) {
            $sum += $entry->getDuration();
        }

        return $sum;
    }

    /**
     * Sort helper used by tests (descending by name).
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @psalm-return int<-1,1>
     */
    protected function sortByName(array $a, array $b): int
    {
        return strcmp((string) $b['name'], (string) $a['name']);
    }

    /**
     * Get entries by request parameter.
     *
     * @return Entry[]
     * @psalm-return array<int, Entry>
     * @throws \Exception
     */
    protected function getEntries(\Symfony\Component\HttpFoundation\Request $request, ?int $maxResults = null): array
    {
        $filters = InterpretationFiltersDto::fromRequest($request);
        $arParams = $filters->toFilterArray($this->isDEV($request) ? $this->getUserId($request) : null, $maxResults);

        $year = $filters->year;
        if (null !== $year) {
            $month = $filters->month;
            if (null !== $month) {
                $datestart = $year.'-'.$month.'-01';
                $dateend = \DateTime::createFromFormat('Y-m-d', $datestart);
                if (false === $dateend) {
                    throw new \Exception('Invalid date');
                }
                $dateend->add(new \DateInterval('P1M'));
                $dateend->sub(new \DateInterval('P1D'));
            } else {
                $datestart = $year.'-01-01';
                $dateend = \DateTime::createFromFormat('Y-m-d', $datestart);
                if (false === $dateend) {
                    throw new \Exception('Invalid date');
                }
                $dateend->add(new \DateInterval('P1Y'));
                $dateend->sub(new \DateInterval('P1D'));
            }
            $arParams['datestart'] = $datestart;
            $arParams['dateend'] = $dateend->format('Y-m-d');
        }

        if (!$arParams['customer'] && !$arParams['project'] && !$arParams['user'] && !$arParams['ticket']) {
            throw new \Exception($this->translate('You need to specify at least customer, project, ticket, user or month and year.'));
        }

        /** @var \App\Repository\EntryRepository $repo */
        $repo = $this->managerRegistry->getRepository(Entry::class);
        return $repo->findByFilterArray($arParams);
    }
}


