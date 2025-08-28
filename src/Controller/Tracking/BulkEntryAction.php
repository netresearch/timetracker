<?php
declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Model\Response;
use App\Response\Error;
use Symfony\Component\HttpFoundation\Request;
use App\Entity\Preset;
use App\Entity\User;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\Contract;

final class BulkEntryAction extends BaseTrackingController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/tracking/bulkentry', name: 'timetracking_bulkentry_attr', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $this->logData($_POST, true);

            $doctrine = $this->managerRegistry;
            $contractHoursArray = [];

            $preset = $doctrine->getRepository(Preset::class)->find((int) $request->request->get('preset'));
            if (!$preset instanceof Preset) {
                throw new \Exception('Preset not found');
            }

            /** @var User $user */
            $user = $doctrine->getRepository(User::class)->find($this->getUserId($request));
            $customer = $doctrine->getRepository(Customer::class)->find($preset->getCustomerId());
            $project = $doctrine->getRepository(Project::class)->find($preset->getProjectId());
            $activity = $doctrine->getRepository(Activity::class)->find($preset->getActivityId());

            if ($request->request->get('usecontract')) {
                $contracts = $doctrine->getRepository(Contract::class)
                    ->findBy(['user' => $this->getUserId($request)], ['start' => 'ASC']);

                foreach ($contracts as $contract) {
                    if (!$contract instanceof Contract) {
                        continue;
                    }

                    $contractHoursArray[] = [
                        'start' => $contract->getStart(),
                        'stop' => $contract->getEnd() ?? new \DateTime((string) $request->request->get('enddate')),
                        7 => $contract->getHours0(),
                        1 => $contract->getHours1(),
                        2 => $contract->getHours2(),
                        3 => $contract->getHours3(),
                        4 => $contract->getHours4(),
                        5 => $contract->getHours5(),
                        6 => $contract->getHours6(),
                    ];
                }

                if (!$contracts) {
                    $response = new Response(
                        $this->translator->trans('No contract for user found. Please use custome time.')
                    );
                    $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

                    return $response;
                }
            }

            $em = $doctrine->getManager();
            $date = new \DateTime((string) ($request->request->get('startdate') ?? ''));
            $endDate = new \DateTime((string) ($request->request->get('enddate') ?? ''));

            $c = 0;
            $weekend = ['0', '6', '7'];
            $regular_holidays = ['01-01','05-01','10-03','10-31','12-25','12-26'];
            $irregular_holidays = ['2012-04-06','2012-04-09','2012-05-17','2012-05-28','2012-11-21','2013-03-29','2013-04-01','2013-05-09','2013-05-20','2013-11-20','2014-04-18','2014-04-21','2014-05-29','2014-06-09','2014-11-19','2015-04-03','2015-04-04','2015-05-14','2015-05-25','2015-11-18'];

            $numAdded = 0;
            do {
                ++$c;
                if ($c > 100) {
                    break;
                }

                if ($request->request->get('skipweekend') && in_array($date->format('w'), $weekend)) {
                    $date->add(new \DateInterval('P1D'));
                    continue;
                }

                if ($request->request->get('skipholidays')) {
                    if (in_array($date->format('m-d'), $regular_holidays)) {
                        $date->add(new \DateInterval('P1D'));
                        continue;
                    }
                    if (in_array($date->format('Y-m-d'), $irregular_holidays)) {
                        $date->add(new \DateInterval('P1D'));
                        continue;
                    }
                }

                if ($request->request->get('usecontract')) {
                    foreach ($contractHoursArray as $contractHourArray) {
                        $workTime = 0;
                        if ($contractHourArray['start'] <= $date && $contractHourArray['stop'] >= $date) {
                            $workTime = $contractHourArray[$date->format('N')];
                            break;
                        }
                    }

                    if (!isset($workTime) || !$workTime) {
                        $date->add(new \DateInterval('P1D'));
                        continue;
                    }

                    $parts = sscanf((string) $workTime, '%d.%d');
                    $hoursPart = (int) ($parts[0] ?? 0);
                    $fractionPart = (int) ($parts[1] ?? 0);
                    $minutesPart = (int) round(60 * ((float) ('0.'.$fractionPart)));
                    $hoursToAdd = new \DateInterval(sprintf('PT%dH%dM', $hoursPart, $minutesPart));
                    $startTime = new \DateTime('08:00:00');
                    $endTime = (new \DateTime('08:00:00'))->add($hoursToAdd);
                } else {
                    $startTime = new \DateTime((string) ($request->request->get('starttime') ?? '00:00:00'));
                    $endTime = new \DateTime((string) ($request->request->get('endtime') ?? '00:00:00'));
                }

                $entry = new Entry();
                $entry->setUser($user)
                    ->setTicket('')
                    ->setDescription($preset->getDescription())
                    ->setDay($date->format('Y-m-d'))
                    ->setStart($startTime->format('H:i:s'))
                    ->setEnd($endTime->format('H:i:s'))
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

                $this->logData($entry->toArray());
                $em->persist($entry);
                $em->flush();
                ++$numAdded;

                $this->calculateClasses($user->getId() ?? 0, $entry->getDay()->format('Y-m-d'));
                $date->add(new \DateInterval('P1D'));
            } while ($date <= $endDate);

            $responseContent = $this->translator->trans('%num% entries have been added', ['%num%' => $numAdded]);
            if (!empty($contractHoursArray) && (new \DateTime((string) ($request->request->get('startdate') ?? ''))) < $contractHoursArray[0]['start']) {
                $responseContent .= '<br/>'.$this->translator->trans('Contract is valid from %date%.', ['%date%' => $contractHoursArray[0]['start']->format('d.m.Y')]);
            }
            if (!empty($contractHoursArray)) {
                $lastContract = end($contractHoursArray);
                if ($endDate > $lastContract['stop']) {
                    $responseContent .= '<br/>'.$this->translator->trans('Contract expired at %date%.', ['%date%' => $lastContract['stop']->format('d.m.Y')]);
                }
            }

            $response = new Response($responseContent);
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_OK);

            return $response;
        } catch (\Exception $exception) {
            $response = new Response($this->translator->trans($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }
    }
}


