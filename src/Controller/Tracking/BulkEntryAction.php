<?php

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
use App\Model\Response;
use DateInterval;
use DateTime;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function count;
use function in_array;
use function sprintf;

final class BulkEntryAction extends BaseTrackingController
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException
     * @throws Exception                                                       when entry creation or validation fails
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/bulkentry', name: 'timetracking_bulkentry_attr', methods: ['POST'])]
    public function __invoke(
        Request $request,
    ): Response {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

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
            $response = new Response($this->translator->trans($errorMessage));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);

            return $response;
        }

        try {
            $this->logData(['preset' => $bulkEntryDto->preset, 'startdate' => $bulkEntryDto->startdate, 'enddate' => $bulkEntryDto->enddate], true);

            $doctrine = $this->managerRegistry;
            $contractHoursArray = [];

            $preset = $doctrine->getRepository(Preset::class)->find($bulkEntryDto->preset);
            if (!$preset instanceof Preset) {
                throw new Exception('Preset not found');
            }

            /** @var User $user */
            $user = $doctrine->getRepository(User::class)->find($this->getUserId($request));
            $customer = $doctrine->getRepository(Customer::class)->find($preset->getCustomerId());
            $project = $doctrine->getRepository(Project::class)->find($preset->getProjectId());
            $activity = $doctrine->getRepository(Activity::class)->find($preset->getActivityId());

            if ($bulkEntryDto->isUseContract()) {
                $contracts = $doctrine->getRepository(Contract::class)
                    ->findBy(['user' => $this->getUserId($request)], ['start' => 'ASC'])
                ;

                foreach ($contracts as $contract) {
                    if (!$contract instanceof Contract) {
                        continue;
                    }

                    $contractHoursArray[] = [
                        'start' => $contract->getStart(),
                        'stop' => $contract->getEnd() ?? new DateTime($bulkEntryDto->enddate ?: 'now'),
                        7 => $contract->getHours0(),
                        1 => $contract->getHours1(),
                        2 => $contract->getHours2(),
                        3 => $contract->getHours3(),
                        4 => $contract->getHours4(),
                        5 => $contract->getHours5(),
                        6 => $contract->getHours6(),
                    ];
                }

                if ([] === $contracts) {
                    $response = new Response(
                        $this->translator->trans('No contract for user found. Please use custome time.'),
                    );
                    $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);

                    return $response;
                }
            }

            $em = $doctrine->getManager();
            $date = new DateTime($bulkEntryDto->startdate ?: 'now');
            $endDate = new DateTime($bulkEntryDto->enddate ?: 'now');

            $c = 0;
            $weekend = ['0', '6', '7'];
            $regular_holidays = ['01-01', '05-01', '10-03', '10-31', '12-25', '12-26'];
            $irregular_holidays = ['2012-04-06', '2012-04-09', '2012-05-17', '2012-05-28', '2012-11-21', '2013-03-29', '2013-04-01', '2013-05-09', '2013-05-20', '2013-11-20', '2014-04-18', '2014-04-21', '2014-05-29', '2014-06-09', '2014-11-19', '2015-04-03', '2015-04-04', '2015-05-14', '2015-05-25', '2015-11-18'];

            $numAdded = 0;
            do {
                ++$c;
                if ($c > 100) {
                    break;
                }

                if ($bulkEntryDto->isSkipWeekend() && in_array($date->format('w'), $weekend, true)) {
                    $date->add(new DateInterval('P1D'));
                    continue;
                }

                if ($bulkEntryDto->isSkipHolidays()) {
                    if (in_array($date->format('m-d'), $regular_holidays, true)) {
                        $date->add(new DateInterval('P1D'));
                        continue;
                    }

                    if (in_array($date->format('Y-m-d'), $irregular_holidays, true)) {
                        $date->add(new DateInterval('P1D'));
                        continue;
                    }
                }

                if ($bulkEntryDto->isUseContract()) {
                    foreach ($contractHoursArray as $contractHourArray) {
                        $workTime = 0;
                        if ($contractHourArray['start'] <= $date && $contractHourArray['stop'] >= $date) {
                            $workTime = $contractHourArray[$date->format('N')];
                            break;
                        }
                    }

                    if (!isset($workTime) || !$workTime) {
                        $date->add(new DateInterval('P1D'));
                        continue;
                    }

                    $parts = sscanf((string) $workTime, '%d.%d');
                    $hoursPart = (int) ($parts[0] ?? 0);
                    $fractionPart = (int) ($parts[1] ?? 0);
                    $minutesPart = (int) round(60 * ((float) ('0.' . $fractionPart)));
                    $hoursToAdd = new DateInterval(sprintf('PT%dH%dM', $hoursPart, $minutesPart));
                    $startTime = new DateTime('08:00:00');
                    $endTime = new DateTime('08:00:00')->add($hoursToAdd);
                } else {
                    $startTime = new DateTime($bulkEntryDto->starttime ?: '00:00:00');
                    $endTime = new DateTime($bulkEntryDto->endtime ?: '00:00:00');
                }

                $entry = new Entry();
                $entry->setUser($user)
                    ->setTicket('')
                    ->setDescription($preset->getDescription())
                    ->setDay($date->format('Y-m-d'))
                    ->setStart($startTime->format('H:i:s'))
                    ->setEnd($endTime->format('H:i:s'))
                    ->setClass(\App\Enum\EntryClass::DAYBREAK)
                    ->calcDuration()
                ;

                if ($project instanceof Project) {
                    $entry->setProject($project);
                }

                if ($activity instanceof Activity) {
                    $entry->setActivity($activity);
                }

                if ($customer instanceof Customer) {
                    $entry->setCustomer($customer);
                }

                $this->logData($entry->toArray());
                $em->persist($entry);
                $em->flush();
                ++$numAdded;

                $this->calculateClasses($user->getId() ?? 0, $entry->getDay()->format('Y-m-d'));
                $date->add(new DateInterval('P1D'));
            } while ($date <= $endDate);

            $responseContent = $this->translator->trans('%num% entries have been added', ['%num%' => $numAdded]);
            if ([] !== $contractHoursArray && (new DateTime($bulkEntryDto->startdate ?: 'now')) < $contractHoursArray[0]['start']) {
                $responseContent .= '<br/>' . $this->translator->trans('Contract is valid from %date%.', ['%date%' => $contractHoursArray[0]['start']->format('d.m.Y')]);
            }

            if ([] !== $contractHoursArray) {
                $lastContract = end($contractHoursArray);
                if ($endDate > $lastContract['stop']) {
                    $responseContent .= '<br/>' . $this->translator->trans('Contract expired at %date%.', ['%date%' => $lastContract['stop']->format('d.m.Y')]);
                }
            }

            $response = new Response($responseContent);
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_OK);

            return $response;
        } catch (Exception $exception) {
            $response = new Response($this->translator->trans($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);

            return $response;
        }
    }
}
