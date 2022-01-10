<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\EntryRepository;
use Doctrine\DBAL\Types\Types;
use Exception;
use Doctrine\ORM\Mapping as ORM;
use App\Model\Base;
use DateTime;

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

    #[ORM\Column(type: Types::STRING, length: 31, nullable: true)]
    protected $ticket;

    #[ORM\Column(name: 'worklog_id', type: Types::INTEGER, nullable: true)]
    protected $worklog_id;

    #[ORM\Column(type: Types::STRING)]
    protected $description;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    protected $day;

    #[ORM\Column(type: Types::TIME_MUTABLE)]
    protected $start;

    #[ORM\Column(name: '`end`', type: Types::TIME_MUTABLE)]
    protected $end;

    #[ORM\Column(type: Types::INTEGER)]
    protected $duration;

    #[ORM\Column(name: 'synced_to_ticketsystem', type: Types::BOOLEAN)]
    protected $syncedToTicketsystem;

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
     *
     * @var string
     */
    protected $externalSummary = '';
    /**
     * Holds an array of labels assigned for the issue.
     *
     * @var array
     */
    protected $externalLabels = [];
    /**
     * ID of the original booked external ticket.
     *
     * @var string e.g. TYPO-1234
     */
    #[ORM\Column(name: 'internal_jira_ticket_original_key')]
    protected $internalJiraTicketOriginalKey;

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
     * @return array
     */
    public function getExternalLabels()
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
     * @throws Exception
     *
     * @return $this
     */
    public function validateDuration()
    {
        if (($this->getStart() instanceof DateTime)
            && ($this->getEnd() instanceof DateTime)
            && ($this->getEnd()->getTimestamp() <= $this->getStart()->getTimestamp())
        ) {
            throw new Exception('Duration must be greater than 0!');
        }

        return $this;
    }

    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id.
     *
     * @return int $id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get userId.
     *
     * @return int $userId
     */
    public function getUserId()
    {
        if (!\is_object($this->getUser())) {
            return null;
        }

        return $this->getUser()->getId();
    }

    /**
     * Get projectId.
     *
     * @return int $projectId
     */
    public function getProjectId()
    {
        if (!\is_object($this->getProject())) {
            return null;
        }

        return $this->getProject()->getId();
    }

    /**
     * Get accountId.
     *
     * @return int $accountId
     */
    public function getAccountId()
    {
        if (!\is_object($this->getAccount())) {
            return null;
        }

        return $this->getAccount()->getId();
    }

    /**
     * Get customerId.
     *
     * @return int $customerId
     */
    public function getCustomerId()
    {
        if (!\is_object($this->getCustomer())) {
            return null;
        }

        return $this->getCustomer()->getId();
    }

    /**
     * Get ActivityId.
     *
     * @return int $ActivityId
     */
    public function getActivityId()
    {
        if (!\is_object($this->getActivity())) {
            return null;
        }

        return $this->getActivity()->getId();
    }

    /**
     * Set ticket.
     *
     * @param string $ticket
     *
     * @return Entry
     */
    public function setTicket($ticket)
    {
        $this->ticket = str_replace(' ', '', $ticket);

        return $this;
    }

    /**
     * Get ticket.
     *
     * @return string $ticket
     */
    public function getTicket()
    {
        return $this->ticket;
    }

    /**
     * Set Jira WorklogId.
     *
     * @param int $worklog_id
     *
     * @return Entry
     */
    public function setWorklogId($worklog_id)
    {
        $this->worklog_id = $worklog_id;

        return $this;
    }

    /**
     * Get Jira WorklogId.
     *
     * @return int $worklog_id
     */
    public function getWorklogId()
    {
        return $this->worklog_id;
    }

    /**
     * Set description.
     *
     * @param string $description
     *
     * @return Entry
     */
    public function setDescription($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Get description.
     *
     * @return string $description
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set day.
     *
     * @param string $day
     *
     * @return Entry
     */
    public function setDay($day)
    {
        if (!$day instanceof DateTime) {
            $day = new DateTime($day);
        }

        $this->day = $day;

        return $this;
    }

    /**
     * Get day.
     *
     * @return DateTime $day
     */
    public function getDay()
    {
        return $this->day;
    }

    /**
     * Set start.
     *
     * @param string $start
     *
     * @return Entry
     */
    public function setStart($start)
    {
        if (!$start instanceof DateTime) {
            $start                = new DateTime($start);
            [$year, $month, $day] = explode('-', $this->getDay()->format('Y-m-d'));
            $start->setDate($year, $month, $day);
        }

        $this->start = $start;
        $this->alignStartAndEnd();

        return $this;
    }

    /**
     * Get start.
     *
     * @return DateTime $start
     */
    public function getStart()
    {
        return $this->start;
    }

    /**
     * Set end.
     *
     * @param string $end
     *
     * @return Entry
     */
    public function setEnd($end)
    {
        if (!$end instanceof DateTime) {
            $end                  = new DateTime($end);
            [$year, $month, $day] = explode('-', $this->getDay()->format('Y-m-d'));
            $end->setDate($year, $month, $day);
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

    /**
     * Get end.
     *
     * @return DateTime $end
     */
    public function getEnd()
    {
        return $this->end;
    }

    /**
     * Set duration.
     *
     * @param int $duration
     *
     * @return Entry
     */
    public function setDuration($duration)
    {
        $this->duration = $duration;

        return $this;
    }

    /**
     * Get duration.
     *
     * @return int $duration
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * Returns duration as formatted hours:minutes string.
     *
     * @return string
     */
    public function getDurationString()
    {
        $nMinutes = $this->getDuration();
        $nHours   = floor($nMinutes / 60);
        $nMinutes = $nMinutes % 60;

        return sprintf('%02d:%02d', $nHours, $nMinutes);
    }

    /**
     * Set project.
     *
     * @return Entry
     */
    public function setProject(Project $project)
    {
        $this->project = $project;

        return $this;
    }

    /**
     * Get project.
     *
     * @return Project $project
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * Set user.
     *
     * @return Entry
     */
    public function setUser(User $user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Get user.
     *
     * @return User $user
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set account.
     *
     * @return Entry
     */
    public function setAccount(Account $account)
    {
        $this->account = $account;

        return $this;
    }

    /**
     * Get account.
     *
     * @return Account $account
     */
    public function getAccount()
    {
        return $this->account;
    }

    /**
     * Set activity.
     *
     * @return Entry
     */
    public function setActivity(Activity $activity)
    {
        $this->activity = $activity;

        return $this;
    }

    /**
     * Get activity.
     *
     * @return Activity $activity
     */
    public function getActivity()
    {
        return $this->activity;
    }

    /**
     * Get array representation of entry object.
     *
     * @return mixed[]
     */
    public function toArray()
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
     *
     * @param int $factor
     *
     * @throws Exception
     *
     * @return Entry
     */
    public function calcDuration($factor = 1)
    {
        if ($this->getStart() && $this->getEnd()) {
            $start = new DateTime($this->getStart()->format('H:i'));
            $end   = new DateTime($this->getEnd()->format('H:i'));

            $difference = ($end->getTimestamp() - $start->getTimestamp()) * $factor / 60;
            $this->setDuration(round($difference));
        } else {
            $this->setDuration(0);
        }
        $this->validateDuration();

        return $this;
    }

    /**
     * Set customer.
     *
     * @return Entry
     */
    public function setCustomer(Customer $customer)
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * Get customer.
     *
     * @return Customer
     */
    public function getCustomer()
    {
        return $this->customer;
    }

    /**
     * Set class.
     *
     * @param int class
     * @param mixed $class
     *
     * @return Entry
     */
    public function setClass($class)
    {
        $this->class = (int) $class;

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
     *
     * @return string
     */
    public function getTicketSystemIssueLink()
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
     *
     * @return string
     */
    public function getInternalJiraTicketOriginalKey()
    {
        return $this->internalJiraTicketOriginalKey;
    }

    /**
     * Returns true, if a original ticket name.
     *
     * @return bool
     */
    public function hasInternalJiraTicketOriginalKey()
    {
        return !empty($this->internalJiraTicketOriginalKey);
    }

    /**
     * Sets the original ticket name.
     *
     * @param string $strTicket
     *
     * @return $this
     */
    public function setInternalJiraTicketOriginalKey($strTicket)
    {
        $this->internalJiraTicketOriginalKey = (string) $strTicket;

        return $this;
    }

    /**
     * Returns the post data for the internal JIRA ticket creation.
     *
     * @return array
     */
    public function getPostDataForInternalJiraTicketCreation()
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

    /**
     * @return bool
     */
    public function getSyncedToTicketsystem()
    {
        return $this->syncedToTicketsystem;
    }

    /**
     * @param bool $syncedToTicketsystem
     *
     * @return $this
     */
    public function setSyncedToTicketsystem($syncedToTicketsystem)
    {
        $this->syncedToTicketsystem = $syncedToTicketsystem;

        return $this;
    }
}
