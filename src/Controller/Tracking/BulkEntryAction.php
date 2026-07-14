<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Dto\BulkEntryDto;
use App\Entity\Activity;
use App\Entity\Contract;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Preset;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\EntryClass;
use App\Event\EntryEvent;
use App\Exception\PresetNotFoundException;
use App\Model\Response;
use DateInterval;
use DateTime;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\Attribute\Required;

use function count;
use function in_array;
use function sprintf;

final class BulkEntryAction extends BaseTrackingController
{
    private const array WEEKEND_DAYS = ['0', '6', '7'];

    private const array REGULAR_HOLIDAYS = ['01-01', '05-01', '10-03', '10-31', '12-25', '12-26'];

    private const array IRREGULAR_HOLIDAYS = ['2012-04-06', '2012-04-09', '2012-05-17', '2012-05-28', '2012-11-21', '2013-03-29', '2013-04-01', '2013-05-09', '2013-05-20', '2013-11-20', '2014-04-18', '2014-04-21', '2014-05-29', '2014-06-09', '2014-11-19', '2015-04-03', '2015-04-04', '2015-05-14', '2015-05-25', '2015-11-18'];

    private ?EventDispatcherInterface $eventDispatcher = null;

    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    #[Required]
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @throws BadRequestException
     * @throws Exception           when entry creation or validation fails
     */
    #[Route(path: '/tracking/bulkentry', name: 'timetracking_bulkentry_attr', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $currentUser,
    ): Response {
        // Create DTO from request data
        $bulkEntryDto = new BulkEntryDto(
            preset: (int) $request->request->get('preset', 0),
            startdate: (string) $request->request->get('startdate', ''),
            enddate: (string) $request->request->get('enddate', ''),
            starttime: (string) $request->request->get('starttime', ''),
            endtime: (string) $request->request->get('endtime', ''),
            usecontract: (int) $request->request->get('usecontract', 0),
            skipweekend: (int) $request->request->get('skipweekend', 0),
            skipholidays: (int) $request->request->get('skipholidays', 0),
        );

        // Validate DTO
        $constraintViolationList = $this->validator->validate($bulkEntryDto);
        if (count($constraintViolationList) > 0) {
            $errorMessage = (string) $constraintViolationList->get(0)->getMessage();

            return $this->createResponse(
                $this->translator->trans($errorMessage),
                \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            return $this->processBulkEntries($bulkEntryDto, $currentUser);
        } catch (Exception $exception) {
            return $this->createResponse(
                $this->translator->trans($exception->getMessage()),
                \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }
    }

    /**
     * @throws Exception when entry creation or persistence fails
     */
    private function processBulkEntries(BulkEntryDto $bulkEntryDto, User $user): Response
    {
        $this->logData(['preset' => $bulkEntryDto->preset, 'startdate' => $bulkEntryDto->startdate, 'enddate' => $bulkEntryDto->enddate], true);

        $doctrine = $this->managerRegistry;

        $preset = $doctrine->getRepository(Preset::class)->find($bulkEntryDto->preset);
        if (!$preset instanceof Preset) {
            throw new PresetNotFoundException('Preset not found');
        }

        $customer = $doctrine->getRepository(Customer::class)->find($preset->getCustomerId());
        $project = $doctrine->getRepository(Project::class)->find($preset->getProjectId());
        $activity = $doctrine->getRepository(Activity::class)->find($preset->getActivityId());

        $contractHoursArray = [];
        if ($bulkEntryDto->isUseContract()) {
            $contractHoursArray = $this->loadContractHours($bulkEntryDto, $user);
            if (null === $contractHoursArray) {
                return $this->createResponse(
                    $this->translator->trans('No contract for user found. Please use custom time.'),
                    \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY,
                );
            }
        }

        $em = $doctrine->getManager();
        $date = $this->createDateOrNow($bulkEntryDto->startdate);
        $endDate = $this->createDateOrNow($bulkEntryDto->enddate);

        $c = 0;
        $numAdded = 0;
        do {
            ++$c;
            if ($c > 100) {
                break;
            }

            if ($this->shouldSkipDay($date, $bulkEntryDto)) {
                $date->add(new DateInterval('P1D'));

                continue;
            }

            $times = $this->resolveDayTimes($date, $contractHoursArray, $bulkEntryDto);
            if (null === $times) {
                $date->add(new DateInterval('P1D'));

                continue;
            }

            $entry = $this->createBulkEntry($preset, $user, $date, $times[0], $times[1], $customer, $project, $activity);

            $this->logData($entry->toArray());
            $em->persist($entry);
            $em->flush();

            // Dispatch entry created event for Jira sync and cache invalidation
            if ($this->eventDispatcher instanceof EventDispatcherInterface) {
                $this->eventDispatcher->dispatch(new EntryEvent($entry), EntryEvent::CREATED);
            }

            ++$numAdded;

            $this->calculateClasses($user->getId() ?? 0, $entry->getDay()->format('Y-m-d'));
            $date->add(new DateInterval('P1D'));
        } while ($date <= $endDate);

        return $this->createResponse(
            $this->buildResultMessage($bulkEntryDto, $contractHoursArray, $endDate, $numAdded),
            \Symfony\Component\HttpFoundation\Response::HTTP_OK,
        );
    }

    /**
     * Builds the per-weekday contract hours lookup for the user's contracts.
     *
     * @throws Exception
     *
     * @return list<array{start: DateTime, stop: DateTime, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}>|null
     *                                                                                                                                 null when the user has no contracts
     */
    private function loadContractHours(BulkEntryDto $bulkEntryDto, User $user): ?array
    {
        $contracts = $this->managerRegistry->getRepository(Contract::class)
            ->findBy(['user' => $user->getId()], ['start' => 'ASC']);

        if ([] === $contracts) {
            return null;
        }

        $contractHoursArray = [];
        foreach ($contracts as $contract) {
            if (!$contract instanceof Contract) {
                continue;
            }

            $contractHoursArray[] = [
                'start' => $contract->getStart(),
                'stop' => $contract->getEnd() ?? $this->createDateOrNow($bulkEntryDto->enddate),
                7 => $contract->getHours0(),
                1 => $contract->getHours1(),
                2 => $contract->getHours2(),
                3 => $contract->getHours3(),
                4 => $contract->getHours4(),
                5 => $contract->getHours5(),
                6 => $contract->getHours6(),
            ];
        }

        return $contractHoursArray;
    }

    private function shouldSkipDay(DateTime $date, BulkEntryDto $bulkEntryDto): bool
    {
        if ($bulkEntryDto->isSkipWeekend() && in_array($date->format('w'), self::WEEKEND_DAYS, true)) {
            return true;
        }

        if (!$bulkEntryDto->isSkipHolidays()) {
            return false;
        }

        return in_array($date->format('m-d'), self::REGULAR_HOLIDAYS, true)
            || in_array($date->format('Y-m-d'), self::IRREGULAR_HOLIDAYS, true);
    }

    /**
     * Resolves the start and end time for the given day.
     *
     * @param list<array{start: DateTime, stop: DateTime, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}> $contractHoursArray
     *
     * @throws Exception
     *
     * @return array{0: DateTime, 1: DateTime}|null null when no contract covers the day or the work time is zero
     */
    private function resolveDayTimes(DateTime $date, array $contractHoursArray, BulkEntryDto $bulkEntryDto): ?array
    {
        if (!$bulkEntryDto->isUseContract()) {
            return [
                new DateTime('' !== $bulkEntryDto->starttime ? $bulkEntryDto->starttime : '00:00:00'),
                new DateTime('' !== $bulkEntryDto->endtime ? $bulkEntryDto->endtime : '00:00:00'),
            ];
        }

        $workTime = 0;
        foreach ($contractHoursArray as $contractHourArray) {
            if ($contractHourArray['start'] <= $date && $contractHourArray['stop'] >= $date) {
                $workTime = $contractHourArray[$date->format('N')];
                break;
            }
        }

        if ($workTime <= 0) {
            return null;
        }

        $hours = (float) $workTime;
        $hoursPart = (int) $hours;
        $minutesPart = (int) round(($hours - $hoursPart) * 60);
        $hoursToAdd = new DateInterval(sprintf('PT%dH%dM', $hoursPart, $minutesPart));

        return [
            new DateTime('08:00:00'),
            new DateTime('08:00:00')->add($hoursToAdd),
        ];
    }

    private function createBulkEntry(
        Preset $preset,
        User $user,
        DateTime $date,
        DateTime $startTime,
        DateTime $endTime,
        ?Customer $customer,
        ?Project $project,
        ?Activity $activity,
    ): Entry {
        $entry = new Entry();
        $entry->setUser($user)
            // ADR-025: the bulk day-break path is a human self-log (source stays
            // the entity default HUMAN, estimated false); stamp the caller as
            // loggedBy so this bypass write path is attributed like the main one.
            ->setLoggedBy($user)
            ->setTicket('')
            ->setDescription($preset->getDescription())
            ->setDay($date->format('Y-m-d'))
            ->setStart($startTime->format('H:i:s'))
            ->setEnd($endTime->format('H:i:s'))
            ->setClass(EntryClass::DAYBREAK)
            ->calcDuration();

        if ($project instanceof Project) {
            $entry->setProject($project);
        }

        if ($activity instanceof Activity) {
            $entry->setActivity($activity);
        }

        if ($customer instanceof Customer) {
            $entry->setCustomer($customer);
        }

        return $entry;
    }

    /**
     * @param list<array{start: DateTime, stop: DateTime, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float}> $contractHoursArray
     *
     * @throws Exception
     */
    private function buildResultMessage(BulkEntryDto $bulkEntryDto, array $contractHoursArray, DateTime $endDate, int $numAdded): string
    {
        $responseContent = $this->translator->trans('%num% entries have been added', ['%num%' => $numAdded]);

        if ([] === $contractHoursArray) {
            return $responseContent;
        }

        if ($this->createDateOrNow($bulkEntryDto->startdate) < $contractHoursArray[0]['start']) {
            $responseContent .= '<br/>' . $this->translator->trans('Contract is valid from %date%.', ['%date%' => $contractHoursArray[0]['start']->format('d.m.Y')]);
        }

        $lastContract = end($contractHoursArray);
        if ($endDate > $lastContract['stop']) {
            $responseContent .= '<br/>' . $this->translator->trans('Contract expired at %date%.', ['%date%' => $lastContract['stop']->format('d.m.Y')]);
        }

        return $responseContent;
    }

    /**
     * @throws Exception
     */
    private function createDateOrNow(string $value): DateTime
    {
        return new DateTime('' !== $value ? $value : 'now');
    }

    private function createResponse(string $content, int $statusCode): Response
    {
        $response = new Response($content);
        $response->setStatusCode($statusCode);

        return $response;
    }
}
