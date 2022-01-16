<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryRepository;
use Doctrine\DBAL\Types\Types;
use Exception;
use Doctrine\ORM\Mapping as ORM;
use App\Model\Base;
use DateTime;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: EntryRepository::class)]
#[ORM\Table(name: 'entries')]
class Entry extends Base
{
    final public const CLASS_PLAIN    = 1;
    final public const CLASS_DAYBREAK = 2;
    final public const CLASS_PAUSE    = 4;
    final public const CLASS_OVERLAP  = 8;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: Types::STRING, length: 31, options: ['default' => ''])]
    protected $ticket = '';

    #[ORM\Column(name: 'worklog_id', type: Types::INTEGER, nullable: true)]
    protected $worklog_id;

    #[ORM\Column(type: Types::STRING, options: ['default' => ''])]
    protected $description = '';

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    protected $day;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    protected $start;

    #[ORM\Column(name: '`end`', type: Types::TIME_MUTABLE)]
    protected $end;

    #[ORM\Column(type: Types::INTEGER)]
    protected $duration;

    #[ORM\Column(name: 'synced_to_ticketsystem', type: Types::BOOLEAN, options: ['default' => 0])]
    protected $syncedToTicketsystem = false;

    #[ORM\ManyToOne(targetEntity: 'Project', inversedBy: 'entries')]
    protected $project;

    #[ORM\ManyToOne(targetEntity: 'Customer', inversedBy: 'entries')]
    protected $customer;

    #[ORM\ManyToOne(targetEntity: 'User', inversedBy: 'entries')]
    protected $user;

    #[ORM\ManyToOne(targetEntity: 'Account', inversedBy: 'entries')]
    protected $account;

    #[ORM\ManyToOne(targetEntity: 'Activity', inversedBy: 'entries')]
    protected $activity;

    #[ORM\Column(name: 'class', type: Types::INTEGER, nullable: false)]
    protected $class = self::CLASS_PLAIN;

    /**
     * holds summary from external ticket system; no mapping for ORM required (yet).
     */
    protected string $externalSummary = '';

    /**
     * Holds an array of labels assigned for the issue.
     */
    protected array $externalLabels = [];

    /**
     * ID of the original booked external ticket.
     *
     * @var string e.g. TYPO-1234
     */
    #[ORM\Column(name: 'internal_jira_ticket_original_key', options: ['default' => ''])]
    protected $internalJiraTicketOriginalKey = '';

    public function setExternalReporter(string $externalReporter): static
    {
        $this->externalReporter = $externalReporter;

        return $this;
    }

    public function getExternalReporter(): string
    {
        return $this->externalReporter;
    }

    public function setExternalSummary(string $externalSummary): static
    {
        $this->externalSummary = $externalSummary;

        return $this;
    }

    /**
     * Returns the array of external labels.
     */
    public function getExternalLabels(): array
    {
        return $this->externalLabels;
    }

    /**
     * Sets the array of external labels.
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
     */
    protected string $externalReporter = '';

    /**
     * @throws Exception
     */
    public function validateDuration(): static
    {
        if (($this->getStart() instanceof DateTime)
            && ($this->getEnd() instanceof DateTime)
            && ($this->getEnd()->getTimestamp() <= $this->getStart()->getTimestamp())
        ) {
            throw new Exception('Duration must be greater than 0!');
        }

        return $this;
    }

    public function setId($id): static
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
        return $this->getUser()?->getId();
    }

    public function getProjectId(): ?int
    {
        return $this->getProject()?->getId();
    }

    public function getAccountId(): ?int
    {
        return $this->getAccount()?->getId();
    }

    public function getCustomerId(): ?int
    {
        return $this->getCustomer()?->getId();
    }

    public function getActivityId(): ?int
    {
        return $this->getActivity()?->getId();
    }

    public function setTicket(string $ticket): static
    {
        $this->ticket = str_replace(' ', '', $ticket);

        return $this;
    }

    public function getTicket(): ?string
    {
        return $this->ticket;
    }

    public function setWorklogId(?int $worklog_id): static
    {
        $this->worklog_id = $worklog_id;

        return $this;
    }

    public function getWorklogId(): ?int
    {
        return $this->worklog_id;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDay(string|DateTimeInterface $day): static
    {
        if (!$day instanceof DateTime) {
            $day = new DateTime($day);
        }

        $this->day = $day;

        return $this;
    }

    public function getDay(): ?DateTimeInterface
    {
        return $this->day;
    }

    public function setStart(string|DateTimeInterface $start): static
    {
        if (!$start instanceof DateTime) {
            $start_time = $start;
            $start      = DateTime::createFromInterface($this->getDay());
            $start->modify($start_time);
        }

        $this->start = $start;
        $this->alignStartAndEnd();

        return $this;
    }

    public function getStart(): ?DateTimeInterface
    {
        return $this->start;
    }

    public function setEnd(string|DateTimeInterface $end): static
    {
        if (!$end instanceof DateTime) {
            $end_time = $end;
            $end      = DateTime::createFromInterface($this->getDay());
            $end->modify($end_time);
        }

        $this->end = $end;
        $this->alignStartAndEnd();

        return $this;
    }

    /**
     *  Make sure end is greater or equal start.
     */
    protected function alignStartAndEnd()
    {
        if (!$this->start instanceof DateTime) {
            return $this;
        }

        if (!$this->end instanceof DateTime) {
            return $this;
        }

        if ($this->end->format('H:i') < $this->start->format('H:i')) {
            $this->end = clone $this->start;
        }

        return $this;
    }

    public function getEnd(): ?DateTimeInterface
    {
        return $this->end;
    }

    public function setDuration(int $duration): static
    {
        $this->duration = $duration;

        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    /**
     * Returns duration as formatted hours:minutes string.
     */
    public function getDurationString(): string
    {
        $nMinutes = $this->getDuration();
        $nHours   = floor($nMinutes / 60);
        $nMinutes = $nMinutes % 60;

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

    public function setUser(User $user): self
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
     * @return mixed[]
     */
    public function toArray(): array
    {
        if (null !== $this->getCustomer()) {
            $customer = $this->getCustomer()->getId();
        } else {
            if ($this->getProject() && $this->getProject()->getCustomer()) {
                $customer = $this->getProject()->getCustomer()->getId();
            } else {
                $customer = null;
            }
        }

        return [
            'id'             => $this->getId(),
            'date'           => $this->getDay() ? $this->getDay()->format('d/m/Y') : null,
            'start'          => $this->getStart() ? $this->getStart()->format('H:i') : null,
            'end'            => $this->getEnd() ? $this->getEnd()->format('H:i') : null,
            'user'           => $this->getUser() ? $this->getUser()->getId() : null,
            'customer'       => $customer,
            'project'        => $this->getProject() ? $this->getProject()->getId() : null,
            'activity'       => $this->getActivity() ? $this->getActivity()->getId() : null,
            'description'    => $this->getDescription(),
            'ticket'         => $this->getTicket(),
            'duration'       => $this->getDuration(),
            'durationString' => $this->getDurationString(),
            'class'          => $this->getClass(),
            'worklog'        => $this->getWorklogId(),
            'extTicket'      => $this->getInternalJiraTicketOriginalKey(),
            'extTicketUrl'   => sprintf($this->getProject()?->getTicketSystem()?->getUrl() ?? '', $this->getInternalJiraTicketOriginalKey()),
        ];
    }

    /**
     * Calculate difference between start and end.
     */
    public function calcDuration(float $factor = 1): static
    {
        if ($this->getStart() && $this->getEnd()) {
            $start = new DateTime($this->getStart()->format('H:i'));
            $end   = new DateTime($this->getEnd()->format('H:i'));

            $difference = ($end->getTimestamp() - $start->getTimestamp()) * $factor / 60;
            $this->setDuration((int) round($difference));
        } else {
            $this->setDuration(0);
        }
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
        $ticketSystem = $this->getProject()->getTicketSystem();

        if (empty($ticketSystem)) {
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
        return !empty($this->internalJiraTicketOriginalKey);
    }

    /**
     * Sets the original ticket name.
     */
    public function setInternalJiraTicketOriginalKey(string $strTicket): static
    {
        $this->internalJiraTicketOriginalKey = (string) $strTicket;

        return $this;
    }

    /**
     * Returns the post data for the internal JIRA ticket creation.
     */
    public function getPostDataForInternalJiraTicketCreation(): array
    {
        return [
            'fields' => [
                'project' => [
                    'key' => $this->getProject()->getInternalJiraProjectKey(),
                ],
                'summary'     => $this->getTicket(),
                'description' => $this->getTicketSystemIssueLink(),
                'issuetype'   => [
                    'name' => 'Task',
                ],
            ],
        ];
    }

    public function getSyncedToTicketsystem(): bool
    {
        return $this->syncedToTicketsystem;
    }

    public function setSyncedToTicketsystem(bool $syncedToTicketsystem): static
    {
        $this->syncedToTicketsystem = $syncedToTicketsystem;

        return $this;
    }
}
