<?php

declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Controller\BaseController;
use App\Dto\InterpretationFiltersDto;
use App\Entity\Entry;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\EntryRepository;
use DateInterval;
use DateTime;
use Exception;
use Symfony\Component\HttpFoundation\Request;

use function assert;
use function is_string;

abstract class BaseInterpretationController extends BaseController
{
    /**
     * @param Entry[] $entries
     *
     * @psalm-param array<Entry> $entries
     *
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
     *
     * @psalm-return int<-1,1>
     */
    protected function sortByName(array $a, array $b): int
    {
        $nameA = isset($a['name']) && is_string($a['name']) ? $a['name'] : '';
        $nameB = isset($b['name']) && is_string($b['name']) ? $b['name'] : '';

        return strcmp($nameB, $nameA);
    }

    /**
     * Get entries by request parameter.
     *
     * @throws Exception
     *
     * @return list<Entry>
     */
    protected function getEntries(Request $request, ?User $currentUser = null, ?int $maxResults = null): array
    {
        $interpretationFiltersDto = InterpretationFiltersDto::fromRequest($request);
        $userId = (UserType::DEV === $currentUser?->getType()) ? $currentUser->getId() : null;
        $arParams = $interpretationFiltersDto->toFilterArray($userId, $maxResults);

        $year = $interpretationFiltersDto->year;
        if (null !== $year) {
            $month = $interpretationFiltersDto->month;
            if (null !== $month) {
                $datestart = $year . '-' . $month . '-01';
                $dateend = DateTime::createFromFormat('Y-m-d', $datestart);
                if (false === $dateend) {
                    throw new Exception('Invalid date');
                }

                $dateend->add(new DateInterval('P1M'));
                $dateend->sub(new DateInterval('P1D'));
            } else {
                $datestart = $year . '-01-01';
                $dateend = DateTime::createFromFormat('Y-m-d', $datestart);
                if (false === $dateend) {
                    throw new Exception('Invalid date');
                }

                $dateend->add(new DateInterval('P1Y'));
                $dateend->sub(new DateInterval('P1D'));
            }

            $arParams['datestart'] = $datestart;
            $arParams['dateend'] = $dateend->format('Y-m-d');
        }

        if (null === $arParams['customer'] && null === $arParams['project'] && null === $arParams['user'] && null === $arParams['ticket']) {
            throw new Exception($this->translate('You need to specify at least customer, project, ticket, user or month and year.'));
        }

        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);

        return $objectRepository->findByFilterArray($arParams);
    }
}
