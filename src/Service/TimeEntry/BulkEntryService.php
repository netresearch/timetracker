<?php

declare(strict_types=1);

namespace App\Service\TimeEntry;

use App\Entity\Activity;
use App\Entity\Contract;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Preset;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Service for handling bulk entry creation.
 */
class BulkEntryService
{
    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var ClassCalculationService|null
     */
    private $classCalculationService;

    /**
     * Regular holidays in format "mm-dd".
     */
    private const REGULAR_HOLIDAYS = [
        "01-01", // New Year's Day
        "05-01", // Labor Day
        "10-03", // German Unity Day
        "10-31", // Reformation Day
        "12-25", // Christmas Day
        "12-26", // Second Christmas Day
    ];

    /**
     * Irregular holidays in format "yyyy-mm-dd".
     */
    private const IRREGULAR_HOLIDAYS = [
        "2012-04-06", "2012-04-09", "2012-05-17", "2012-05-28", "2012-11-21",
        "2013-03-29", "2013-04-01", "2013-05-09", "2013-05-20", "2013-11-20",
        "2014-04-18", "2014-04-21", "2014-05-29", "2014-06-09", "2014-11-19",
        "2015-04-03", "2015-04-04", "2015-05-14", "2015-05-25", "2015-11-18",
    ];

    /**
     * Days considered as weekend (0 = Sunday, 6 = Saturday, 7 = also Sunday).
     */
    private const WEEKEND_DAYS = ['0', '6', '7'];

    public function __construct(
        ManagerRegistry $doctrine,
        LoggerInterface $trackingLogger,
        TranslatorInterface $translator
    ) {
        $this->doctrine = $doctrine;
        $this->logger = $trackingLogger;
        $this->translator = $translator;
    }

    /**
     * @required
     */
    public function setClassCalculationService(ClassCalculationService $classCalculationService): void
    {
        $this->classCalculationService = $classCalculationService;
    }

    /**
     * Process bulk entry creation.
     *
     * @param array $data Bulk entry request data
     * @param int $userId User ID
     * @return array Result with status and messages
     * @throws \Exception If validation fails or other errors occur
     */
    public function processBulkEntries(array $data, int $userId): array
    {
        $this->logData($data, true);

        // Find preset
        $preset = $this->doctrine->getRepository(Preset::class)->find((int) $data['preset']);
        if (! is_object($preset)) {
            throw new \Exception('Preset not found');
        }

        // Retrieve needed objects
        /** @var User $user */
        $user = $this->doctrine->getRepository(User::class)->find($userId);
        /** @var Customer $customer */
        $customer = $this->doctrine->getRepository(Customer::class)->find($preset->getCustomerId());
        /** @var Project $project */
        $project = $this->doctrine->getRepository(Project::class)->find($preset->getProjectId());
        /** @var Activity $activity */
        $activity = $this->doctrine->getRepository(Activity::class)->find($preset->getActivityId());

        $contractHoursArray = null;

        // If using contract hours
        if (isset($data['usecontract']) && $data['usecontract']) {
            /** @var Contract[] $contracts */
            $contracts = $this->doctrine->getRepository(Contract::class)
                ->findBy(['user' => $userId], ['start' => 'ASC']);

            // Error when no contract exists
            if (!$contracts) {
                throw new \Exception(
                    $this->translator->trans('No contract for user found. Please use custom time.')
                );
            }

            $contractHoursArray = [];
            foreach ($contracts as $contract) {
                $contractHoursArray[] = [
                    'start' => $contract->getStart(),
                    // when user contract has no stop date, take the end date of bulkentry
                    'stop'  => $contract->getEnd() ?? new \DateTime($data['enddate']),
                    7 => $contract->getHours0(), // Sunday
                    1 => $contract->getHours1(), // Monday
                    2 => $contract->getHours2(), // Tuesday
                    3 => $contract->getHours3(), // Wednesday
                    4 => $contract->getHours4(), // Thursday
                    5 => $contract->getHours5(), // Friday
                    6 => $contract->getHours6(), // Saturday
                ];
            }
        }

        $em = $this->doctrine->getManager();
        $date = new \DateTime($data['startdate'] ?: '');
        $endDate = new \DateTime($data['enddate'] ?: '');

        $messages = [];
        $numAdded = 0;
        $securityCounter = 0;

        do {
            // Loop security
            $securityCounter++;
            if ($securityCounter > 100) {
                break;
            }

            // Skip weekends if requested
            if (isset($data['skipweekend']) && $data['skipweekend']
                && in_array($date->format('w'), self::WEEKEND_DAYS)
            ) {
                $date->add(new \DateInterval('P1D'));
                continue;
            }

            // Skip holidays if requested
            if (isset($data['skipholidays']) && $data['skipholidays']) {
                // Skip regular holidays
                if (in_array($date->format("m-d"), self::REGULAR_HOLIDAYS)) {
                    $date->add(new \DateInterval('P1D'));
                    continue;
                }

                // Skip irregular holidays
                if (in_array($date->format("Y-m-d"), self::IRREGULAR_HOLIDAYS)) {
                    $date->add(new \DateInterval('P1D'));
                    continue;
                }
            }

            // Handle time calculation
            if (isset($data['usecontract']) && $data['usecontract']) {
                $workTime = 0;

                // Find applicable contract for this date
                foreach ($contractHoursArray as $contractHourArray) {
                    if ($contractHourArray['start'] <= $date && $contractHourArray['stop'] >= $date) {
                        $workTime = $contractHourArray[$date->format('N')];
                        break;
                    }
                }

                // Skip days without work time
                if (!$workTime) {
                    $date->add(new \DateInterval('P1D'));
                    continue;
                }

                // Parse work time (handling decimal hours)
                // Ensure workTime is converted to string to avoid TypeError
                $workTimeStr = (string)$workTime;
                $workTimeArray = sscanf($workTimeStr, '%d.%d');
                $hours = $workTimeArray[0];
                $minutes = isset($workTimeArray[1]) ? (int)(60 * ('0.' . $workTimeArray[1])) : 0;

                $hoursToAdd = new \DateInterval('PT' . $hours . 'H' . $minutes . 'M');
                $startTime = new \DateTime('08:00:00');
                $endTime = (clone $startTime)->add($hoursToAdd);
            } else {
                $startTime = new \DateTime($data['starttime'] ?: null);
                $endTime = new \DateTime($data['endtime'] ?: null);
            }

            // Create entry
            $entry = new Entry();
            $entry->setUser($user)
                ->setTicket('')
                ->setDescription($preset->getDescription())
                ->setDay($date)
                ->setStart($startTime->format('H:i:s'))
                ->setEnd($endTime->format('H:i:s'))
                ->calcDuration();

            if ($project) {
                $entry->setProject($project);
            }

            if ($activity) {
                $entry->setActivity($activity);
            }

            if ($customer) {
                $entry->setCustomer($customer);
            }

            // Write log
            $this->logData($entry->toArray());

            // Save entry
            $em->persist($entry);
            $em->flush();
            $numAdded++;

            // Calculate color lines for the changed days
            if ($this->classCalculationService) {
                $this->classCalculationService->calculateClasses($user->getId(), $entry->getDay()->format("Y-m-d"));
            } else {
                $this->calculateClasses($user->getId(), $entry->getDay()->format("Y-m-d"));
            }

            $date->add(new \DateInterval('P1D'));
        } while ($date <= $endDate);

        // Main success message
        $messages[] = $this->translator->trans(
            '%num% entries have been added',
            ['%num%' => $numAdded]
        );

        // Add additional messages about contract validity if needed
        if (isset($contractHoursArray)) {
            // Contract starts during bulk entry period
            if (new \DateTime($data['startdate']) < $contractHoursArray[0]['start']) {
                $messages[] = $this->translator->trans(
                    "Contract is valid from %date%.",
                    ['%date%' => $contractHoursArray[0]['start']->format('d.m.Y')]
                );
            }

            // Contract ends during bulk entry period
            if ($endDate > end($contractHoursArray)['stop']) {
                $messages[] = $this->translator->trans(
                    "Contract expired at %date%.",
                    ['%date%' => end($contractHoursArray)['stop']->format('d.m.Y')]
                );
            }
        }

        return [
            'success' => true,
            'messages' => $messages,
            'numAdded' => $numAdded
        ];
    }

    /**
     * Calculate color classes for days.
     *
     * @param int $userId User ID
     * @param string $day Day in format Y-m-d
     */
    private function calculateClasses(int $userId, string $day): void
    {
        $em = $this->doctrine->getManager();
        $conn = $this->doctrine->getConnection();

        $date = new \DateTime($day);
        $sql = "DELETE FROM timetracker_daybyday WHERE user = :user AND day = :day";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user', $userId);
        $stmt->bindValue(':day', $date->format('Y-m-d'));
        $stmt->execute();

        $sql = "
        SELECT
            IFNULL(SUM(TIME_TO_SEC(TIMEDIFF(end, start))), 0) as work_in_seconds
        FROM
            timetracker_entry
        WHERE
            user = :user AND
            day = :day
        ";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user', $userId);
        $stmt->bindValue(':day', $date->format('Y-m-d'));
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row && isset($row['work_in_seconds'])) {
            $weekDay = $date->format('N');
            $workedHours = $row['work_in_seconds'] / 3600.0;

            $userContractRepo = $this->doctrine->getRepository(Contract::class);
            $userContracts = $userContractRepo->findBy([
                'user' => $userId,
                'start' => ['<=', $date]
            ], ['start' => 'ASC']);

            // Filter contracts that are valid for this day
            $userContracts = array_filter($userContracts, function (Contract $contract) use ($date) {
                return $contract->getEnd() === null || $contract->getEnd() >= $date;
            });

            $targetHours = 0;
            if (count($userContracts) > 0) {
                /** @var Contract $contract */
                $contract = $userContracts[0];
                switch ($weekDay) {
                    case 1:
                        $targetHours = (float) $contract->getHours1();
                        break;
                    case 2:
                        $targetHours = (float) $contract->getHours2();
                        break;
                    case 3:
                        $targetHours = (float) $contract->getHours3();
                        break;
                    case 4:
                        $targetHours = (float) $contract->getHours4();
                        break;
                    case 5:
                        $targetHours = (float) $contract->getHours5();
                        break;
                    case 6:
                        $targetHours = (float) $contract->getHours6();
                        break;
                    case 7:
                        $targetHours = (float) $contract->getHours0();
                        break;
                }
            }

            $class = 'default';
            if ($targetHours > 0) {
                if ($workedHours >= $targetHours) {
                    $class = 'success';
                } elseif ($workedHours >= $targetHours * 0.7) {
                    $class = 'warning';
                } else {
                    $class = 'danger';
                }
            } else {
                if ($workedHours > 0) {
                    $class = 'primary';
                }
            }

            $sql = "
            INSERT INTO timetracker_daybyday
                (user, day, class, target_hours, worked_hours, target_seconds, worked_seconds)
            VALUES
                (:user, :day, :class, :targetHours, :workedHours, :targetSeconds, :workedSeconds)
            ";

            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':user', $userId);
            $stmt->bindValue(':day', $date->format('Y-m-d'));
            $stmt->bindValue(':class', $class);
            $stmt->bindValue(':targetHours', $targetHours);
            $stmt->bindValue(':workedHours', $workedHours);
            $stmt->bindValue(':targetSeconds', $targetHours * 3600);
            $stmt->bindValue(':workedSeconds', $row['work_in_seconds']);
            $stmt->execute();
        }
    }

    /**
     * Log data for tracking.
     *
     * @param array $data Data to log
     * @param bool $raw Whether to log raw data
     */
    private function logData(array $data, bool $raw = false): void
    {
        if ($raw) {
            $this->logger->debug('Raw Data', $data);
        } else {
            $this->logger->debug('Entry', $data);
        }
    }
}
