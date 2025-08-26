<?php

namespace App\Entity;

use App\Model\Base;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\EntryRepository::class)]
#[ORM\Table(name: 'entries')]
class Entry extends Base
{
    public const CLASS_PLAIN = 1;

    public const CLASS_DAYBREAK = 2;

    public const CLASS_PAUSE = 4;

    public const CLASS_OVERLAP = 8;

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
    protected ?\DateTimeInterface $day = null;

    #[ORM\Column(type: 'time', nullable: false)]
    protected ?\DateTimeInterface $start = null;

    #[ORM\Column(type: 'time', nullable: false)]
    protected ?\DateTimeInterface $end = null;

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

    /**
     * @param string $externalReporter
     */
    public function setExternalReporter($externalReporter): void
    {
        $this->externalReporter = $externalReporter;
    }

    /**
     * @return string
     */
    public function getExternalReporter()
    {
        return $this->externalReporter;
    }

    /**
     * @param string $externalSummary
     */
    public function setExternalSummary($externalSummary): void
    {
        $this->externalSummary = $externalSummary;
    }

    /**
     * Returns the array of external labels.
     *
     * @return array<int, string>
     */
    public function getExternalLabels()
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

    /**
     * @return string
     */
    public function getExternalSummary()
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
     * @throws \Exception
     *
     * @return $this
     */
    public function validateDuration(): static
    {
        if ($this->end instanceof \DateTime && $this->start instanceof \DateTime && $this->end->getTimestamp() <= $this->start->getTimestamp()) {
            throw new \Exception('Duration must be greater than 0!');
        }

        return $this;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Get userId.
     *
     * @return int|null $userId
     */
    public function getUserId(): ?int
    {
        return is_object($this->getUser()) ? $this->getUser()->getId() : 0;
    }

    /**
     * Get projectId.
     *
     * @return int|null $projectId
     */
    public function getProjectId(): ?int
    {
        return is_object($this->getProject()) ? $this->getProject()->getId() : 0;
    }

    /**
     * Get accountId.
     *
     * @return int|null $accountId
     */
    public function getAccountId(): ?int
    {
        return is_object($this->getAccount()) ? $this->getAccount()->getId() : 0;
    }

    /**
     * Get customerId.
     *
     * @return int|null $customerId
     */
    public function getCustomerId(): ?int
    {
        return is_object($this->getCustomer()) ? $this->getCustomer()->getId() : 0;
    }

    /**
     * Get ActivityId.
     *
     * @return int $ActivityId
     */
    public function getActivityId(): int
    {
        return (int) (is_object($this->getActivity()) ? $this->getActivity()->getId() : 0);
    }

    /**
     * Set ticket.
     *
     * @param string $ticket
     */
    public function setTicket($ticket): static
    {
        $this->ticket = str_replace(' ', '', $ticket);

        return $this;
    }

    /**
     * Get ticket.
     *
     * @return string $ticket
     */
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
     *
     * @return int $worklog_id
     */
    public function getWorklogId(): ?int
    {
        return $this->worklog_id;
    }

    /**
     * Set description.
     */
    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description.
     *
     * @return string $description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Set day.
     *
     * @param string $day
     */
    public function setDay($day): static
    {
        if (!$day instanceof \DateTimeInterface) {
            $day = new \DateTime((string) $day);
        }

        $this->day = $day;

        return $this;
    }

    /**
     * Get day.
     *
     * @return \DateTime $day
     */
    public function getDay(): ?\DateTime
    {
        return $this->day instanceof \DateTime ? $this->day : null;
    }

    /**
     * Set start.
     *
     * @param string $start
     */
    public function setStart($start): static
    {
        if (!$start instanceof \DateTimeInterface) {
            $start = new \DateTime((string) $start);
            $dayObj = $this->getDay();
            if ($dayObj instanceof \DateTimeInterface) {
                [$year, $month, $day] = explode('-', $dayObj->format('Y-m-d'));
                $start->setDate((int) $year, (int) $month, (int) $day);
            }
        }

        $this->start = $start;
        $this->alignStartAndEnd();

        return $this;
    }

    /**
     * Get start.
     *
     * @return \DateTime $start
     */
    public function getStart(): ?\DateTime
    {
        return $this->start instanceof \DateTime ? $this->start : null;
    }

    /**
     * Set end.
     *
     * @param string $end
     */
    public function setEnd($end): static
    {
        if (!$end instanceof \DateTimeInterface) {
            $end = new \DateTime((string) $end);
            $dayObj = $this->getDay();
            if ($dayObj instanceof \DateTimeInterface) {
                [$year, $month, $day] = explode('-', $dayObj->format('Y-m-d'));
                $end->setDate((int) $year, (int) $month, (int) $day);
            }
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
        if ($this->end instanceof \DateTime && $this->start instanceof \DateTime && $this->end->format('H:i') < $this->start->format('H:i')) {
            $this->end = clone $this->start;
        }

        return $this;
    }

    /**
     * Get end.
     *
     * @return \DateTime $end
     */
    public function getEnd(): ?\DateTime
    {
        return $this->end instanceof \DateTime ? $this->end : null;
    }

    /**
     * Set duration.
     */
    public function setDuration(int $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    /**
     * Get duration.
     *
     * @return int $duration
     */
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

    /**
     * Set project.
     */
    public function setProject(Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    /**
     * Get project.
     *
     * @return Project $project
     */
    public function getProject(): ?Project
    {
        return $this->project;
    }

    /**
     * Set user.
     */
    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user.
     *
     * @return User $user
     */
    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * Set account.
     */
    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    /**
     * Get account.
     *
     * @return Account $account
     */
    public function getAccount(): ?Account
    {
        return $this->account;
    }

    /**
     * Set activity.
     */
    public function setActivity(Activity $activity): static
    {
        $this->activity = $activity;

        return $this;
    }

    /**
     * Get activity.
     *
     * @return Activity $activity
     */
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
     */
    public function toArray(): array
    {
        if ($this->getCustomer() instanceof Customer) {
            $customer = $this->getCustomer()->getId();
        } elseif ($this->getProject() && $this->getProject()->getCustomer()) {
            $customer = $this->getProject()->getCustomer()->getId();
        } else {
            $customer = null;
        }

        return [
            'id' => $this->getId(),
            'date' => $this->getDay() instanceof \DateTime ? $this->getDay()->format('d/m/Y') : null,
            'start' => $this->getStart() instanceof \DateTime ? $this->getStart()->format('H:i') : null,
            'end' => $this->getEnd() instanceof \DateTime ? $this->getEnd()->format('H:i') : null,
            'user' => $this->getUser() instanceof User ? $this->getUser()->getId() : null,
            'customer' => $customer,
            'project' => $this->getProject() instanceof Project ? $this->getProject()->getId() : null,
            'activity' => $this->getActivity() instanceof Activity ? $this->getActivity()->getId() : null,
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
     * @param int $factor
     *
     * @throws \Exception
     */
    public function calcDuration($factor = 1): static
    {
        if ($this->getStart() && $this->getEnd()) {
            $start = new \DateTime($this->getStart()->format('H:i'));
            $end = new \DateTime($this->getEnd()->format('H:i'));

            $difference = ($end->getTimestamp() - $start->getTimestamp()) * $factor / 60;
            $this->setDuration((int) round($difference));
        } else {
            $this->setDuration(0);
        }

        $this->validateDuration();

        return $this;
    }

    /**
     * Set customer.
     */
    public function setCustomer(Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * Get customer.
     */
    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    /**
     * Set class.
     *
     * @param int $class
     */
    public function setClass($class): static
    {
        $this->class = $class;

        return $this;
    }

    /**
     * Get class.
     *
     * @return int $class
     */
    public function getClass()
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
     *
     * @return $this
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
        return [
            'fields' => [
                'project' => [
                    'key' => $this->getProject()->getInternalJiraProjectKey(),
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

    /**
     * @return $this
     */
    public function setSyncedToTicketsystem(bool $syncedToTicketsystem): static
    {
        $this->syncedToTicketsystem = $syncedToTicketsystem;

        return $this;
    }
}
