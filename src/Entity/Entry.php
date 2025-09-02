<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Base;
use DateTime;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use Exception;

use function sprintf;

#[ORM\Entity(repositoryClass: \App\Repository\EntryRepository::class)]
#[ORM\Table(name: 'entries')]
class Entry extends Base
{
    public const int CLASS_PLAIN = 1;

    public const int CLASS_DAYBREAK = 2;

    public const int CLASS_PAUSE = 4;

    public const int CLASS_OVERLAP = 8;

    /**
     * Non-persisted runtime flag indicating if the entry is billable based on external labels.
     */
    protected ?bool $billable = null;

    public function setBillable(bool $billable): static
    {
        $this->billable = $billable;

        return $this;
    }

    public function getBillable(): ?bool
    {
        return $this->billable;
    }

    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: 'string', length: 32)]
    protected string $ticket = '';

    #[ORM\Column(name: 'worklog_id', type: 'integer', nullable: true)]
    protected ?int $worklog_id = null;

    #[ORM\Column(type: 'string')]
    protected string $description = '';

    #[ORM\Column(type: 'date', nullable: false)]
    protected DateTimeInterface $day;

    #[ORM\Column(type: 'time', nullable: false)]
    protected DateTimeInterface $start;

    #[ORM\Column(type: 'time', nullable: false)]
    protected DateTimeInterface $end;

    #[ORM\Column(type: 'integer')]
    protected int $duration = 0;

    #[ORM\Column(name: 'synced_to_ticketsystem', type: 'boolean', nullable: true)]
    protected ?bool $syncedToTicketsystem = false;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id')]
    protected ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id')]
    protected ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    protected ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'account_id', referencedColumnName: 'id')]
    protected ?Account $account = null;

    #[ORM\ManyToOne(targetEntity: Activity::class, inversedBy: 'entries')]
    #[ORM\JoinColumn(name: 'activity_id', referencedColumnName: 'id')]
    protected ?Activity $activity = null;

    /**
     * @var int
     */
    #[ORM\Column(name: 'class', type: 'smallint', nullable: false, options: ['unsigned' => true, 'default' => 0])]
    protected $class = self::CLASS_PLAIN;

    /**
     * holds summary from external ticket system; no mapping for ORM required (yet).
     *
     * @var string
     */
    protected $externalSummary = '';

    /**
     * Holds an array of labels assigned for the issue.
     *
     * @var array<int, string>
     */
    protected $externalLabels = [];

    /**
     * ID of the original booked external ticket.
     *
     * @var string e.g. TYPO-1234
     */
    #[ORM\Column(name: 'internal_jira_ticket_original_key', type: 'string', length: 50, nullable: true)]
    protected ?string $internalJiraTicketOriginalKey = null;

    /**
     * Title in ticket system; no ORM mapping.
     */
    protected ?string $ticketTitle = null;

    public function setExternalReporter(string $externalReporter): void
    {
        $this->externalReporter = $externalReporter;
    }

    public function getExternalReporter(): string
    {
        return $this->externalReporter;
    }

    public function setExternalSummary(string $externalSummary): void
    {
        $this->externalSummary = $externalSummary;
    }

    /**
     * Returns the array of external labels.
     *
     * @return array<int, string>
     */
    public function getExternalLabels(): array
    {
        return $this->externalLabels;
    }

    /**
     * Sets the array of external labels.
     *
     * @param array<int, string> $arExternalLabels
     */
    public function setExternalLabels(array $arExternalLabels): void
    {
        $this->externalLabels = $arExternalLabels;
    }

    public function getExternalSummary(): string
    {
        return $this->externalSummary;
    }

    /**
     * holds external reporter; no mapping for ORM required (yet).
     *
     * @var string
     */
    protected $externalReporter = '';

    /**
     * @throws Exception
     *
     * @return $this
     */
    public function validateDuration(): static
    {
        if ($this->end->getTimestamp() <= $this->start->getTimestamp()) {
            throw new Exception('Duration must be greater than 0!');
        }

        return $this;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): ?int
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return $user->getId();
        }

        return 0;
    }

    public function getProjectId(): ?int
    {
        $project = $this->getProject();
        if ($project instanceof Project) {
            return $project->getId();
        }

        return 0;
    }

    public function getAccountId(): ?int
    {
        $account = $this->getAccount();
        if ($account instanceof Account) {
            return $account->getId();
        }

        return 0;
    }

    public function getCustomerId(): ?int
    {
        $customer = $this->getCustomer();
        if ($customer instanceof Customer) {
            return $customer->getId();
        }

        return 0;
    }

    public function getActivityId(): int
    {
        $activity = $this->getActivity();
        if ($activity instanceof Activity) {
            return (int) $activity->getId();
        }

        return 0;
    }

    public function setTicket(string $ticket): static
    {
        $this->ticket = str_replace(' ', '', $ticket);

        return $this;
    }

    public function getTicket(): string
    {
        return $this->ticket;
    }

    public function setTicketTitle(?string $ticketTitle): static
    {
        $this->ticketTitle = $ticketTitle;

        return $this;
    }

    public function getTicketTitle(): ?string
    {
        return $this->ticketTitle;
    }

    /**
     * Set Jira WorklogId.
     */
    public function setWorklogId(?int $worklog_id): static
    {
        $this->worklog_id = $worklog_id;

        return $this;
    }

    /**
     * Get Jira WorklogId.
     */
    public function getWorklogId(): ?int
    {
        return $this->worklog_id;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDay(DateTimeInterface|string $day): static
    {
        if (!$day instanceof DateTimeInterface) {
            $day = new DateTime($day);
        }

        $this->day = $day;

        return $this;
    }

    public function getDay(): DateTimeInterface
    {
        return $this->day;
    }

    public function setStart(DateTimeInterface|string $start): static
    {
        if (!$start instanceof DateTimeInterface) {
            $start = new DateTime($start);
            $dayObj = $this->getDay();
            [$year, $month, $day] = explode('-', $dayObj->format('Y-m-d'));
            $start->setDate((int) $year, (int) $month, (int) $day);
        }

        $this->start = $start;
        $this->alignStartAndEnd();

        return $this;
    }

    public function getStart(): DateTimeInterface
    {
        return $this->start;
    }

    public function setEnd(DateTimeInterface|string $end): static
    {
        if (!$end instanceof DateTimeInterface) {
            $end = new DateTime($end);
            $dayObj = $this->getDay();
            [$year, $month, $day] = explode('-', $dayObj->format('Y-m-d'));
            $end->setDate((int) $year, (int) $month, (int) $day);
        }

        $this->end = $end;
        $this->alignStartAndEnd();

        return $this;
    }

    /**
     *  Make sure end is greater or equal start.
     */
    protected function alignStartAndEnd(): static
    {
        /*
         * Guard for partially initialized entity during construction/hydration.
         *
         * @psalm-suppress RedundantPropertyInitializationCheck
         * @psalm-suppress TypeDoesNotContainType
         */
        if (!isset($this->start) || !isset($this->end)) {
            return $this;
        }

        if ($this->end->format('H:i') < $this->start->format('H:i')) {
            $this->end = clone $this->start;
        }

        return $this;
    }

    public function getEnd(): DateTimeInterface
    {
        return $this->end;
    }

    public function setDuration(int $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    /**
     * Returns duration as formatted hours:minutes string.
     */
    public function getDurationString(): string
    {
        $nMinutes = $this->getDuration();
        $nHours = floor($nMinutes / 60);
        $nMinutes %= 60;

        return sprintf('%02d:%02d', $nHours, $nMinutes);
    }

    public function setProject(Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setActivity(Activity $activity): static
    {
        $this->activity = $activity;

        return $this;
    }

    public function getActivity(): ?Activity
    {
        return $this->activity;
    }

    /**
     * Get array representation of entry object.
     *
     * @return (int|string|null)[]
     *
     * @psalm-return array{id: int|null, date: null|string, start: null|string, end: null|string, user: int|null, customer: int|null, project: int|null, activity: int|null, description: string, ticket: string, duration: int, durationString: string, class: int, worklog: int|null, extTicket: string|null}
     *
     * @psalm-suppress RedundantPropertyInitializationCheck
     */
    public function toArray(): array
    {
        $customerEntity = $this->getCustomer();
        $projectEntity = $this->getProject();
        if ($customerEntity instanceof Customer) {
            $customer = $customerEntity->getId();
        } elseif ($projectEntity instanceof Project) {
            $projectCustomer = $projectEntity->getCustomer();
            $customer = $projectCustomer instanceof Customer ? $projectCustomer->getId() : null;
        } else {
            $customer = null;
        }

        /** @psalm-suppress RedundantPropertyInitializationCheck */
        $userEntity = $this->getUser();
        $activityEntity = $this->getActivity();

        /* @psalm-suppress RedundantPropertyInitializationCheck */
        return [
            'id' => $this->getId(),
            'date' => isset($this->day) ? $this->getDay()->format('d/m/Y') : null,
            'start' => isset($this->start) ? $this->getStart()->format('H:i') : null,
            'end' => isset($this->end) ? $this->getEnd()->format('H:i') : null,
            'user' => $userEntity instanceof User ? $userEntity->getId() : null,
            'customer' => $customer,
            'project' => $projectEntity instanceof Project ? $projectEntity->getId() : null,
            'activity' => $activityEntity instanceof Activity ? $activityEntity->getId() : null,
            'description' => $this->getDescription(),
            'ticket' => $this->getTicket(),
            'duration' => $this->getDuration(),
            'durationString' => $this->getDurationString(),
            'class' => $this->getClass(),
            'worklog' => $this->getWorklogId(),
            'extTicket' => $this->getInternalJiraTicketOriginalKey(),
        ];
    }

    /**
     * Calculate difference between start and end.
     *
     * @throws Exception
     */
    public function calcDuration(float $factor = 1.0): static
    {
        /*
         * Guard for partially initialized entity during construction/hydration.
         *
         * @psalm-suppress RedundantPropertyInitializationCheck
         * @psalm-suppress TypeDoesNotContainType
         */
        if (!isset($this->start) || !isset($this->end)) {
            $this->setDuration(0);

            return $this;
        }

        $start = new DateTime($this->start->format('H:i'));
        $end = new DateTime($this->end->format('H:i'));

        $difference = ($end->getTimestamp() - $start->getTimestamp()) * $factor / 60;
        $this->setDuration((int) round($difference));

        $this->validateDuration();

        return $this;
    }

    public function setCustomer(Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setClass(int $class): static
    {
        $this->class = $class;

        return $this;
    }

    public function getClass(): int
    {
        return $this->class;
    }

    /**
     * Returns the issue link for the configured ticket system.
     */
    public function getTicketSystemIssueLink(): string
    {
        $project = $this->getProject();
        $ticketSystem = $project instanceof Project ? $project->getTicketSystem() : null;

        if (!$ticketSystem instanceof TicketSystem) {
            return $this->getTicket();
        }

        $ticketUrl = $ticketSystem->getTicketUrl();

        if (empty($ticketUrl)) {
            return $this->getTicket();
        }

        return sprintf($ticketUrl, $this->getTicket());
    }

    /**
     * Returns the original ticket name.
     */
    public function getInternalJiraTicketOriginalKey(): ?string
    {
        return $this->internalJiraTicketOriginalKey;
    }

    /**
     * Returns true, if a original ticket name.
     */
    public function hasInternalJiraTicketOriginalKey(): bool
    {
        return null !== $this->internalJiraTicketOriginalKey && '' !== $this->internalJiraTicketOriginalKey;
    }

    /**
     * Sets the original ticket name.
     */
    public function setInternalJiraTicketOriginalKey(?string $strTicket): static
    {
        $this->internalJiraTicketOriginalKey = $strTicket;

        return $this;
    }

    /**
     * Returns the post data for the internal JIRA ticket creation.
     *
     * @return ((mixed|string)[]|string)[][]
     *
     * @psalm-return array{fields: array{project: array{key: mixed}, summary: string, description: string, issuetype: array{name: 'Task'}}}
     */
    public function getPostDataForInternalJiraTicketCreation(): array
    {
        $project = $this->getProject();
        if (!$project instanceof Project) {
            return [
                'fields' => [
                    'project' => [
                        'key' => '',
                    ],
                    'summary' => $this->getTicket(),
                    'description' => $this->getTicketSystemIssueLink(),
                    'issuetype' => [
                        'name' => 'Task',
                    ],
                ],
            ];
        }

        return [
            'fields' => [
                'project' => [
                    'key' => (string) $project->getInternalJiraProjectKey(),
                ],
                'summary' => $this->getTicket(),
                'description' => $this->getTicketSystemIssueLink(),
                'issuetype' => [
                    'name' => 'Task',
                ],
            ],
        ];
    }

    public function getSyncedToTicketsystem(): bool
    {
        return (bool) $this->syncedToTicketsystem;
    }

    public function setSyncedToTicketsystem(bool $syncedToTicketsystem): static
    {
        $this->syncedToTicketsystem = $syncedToTicketsystem;

        return $this;
    }
}
