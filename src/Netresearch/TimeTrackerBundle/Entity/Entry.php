<?php
namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Netresearch\TimeTrackerBundle\Model\Base as Base;
use DateTime as DateTime;

/**
 *
 * @ORM\Entity
 * @ORM\Table(name="entries")
 * @ORM\Entity(repositoryClass="Netresearch\TimeTrackerBundle\Entity\EntryRepository")
 */
class Entry extends Base
{
    const CLASS_PLAIN       = 1;
    const CLASS_DAYBREAK    = 2;
    const CLASS_PAUSE       = 4;
    const CLASS_OVERLAP     = 8;


    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=31, nullable=true)
     */
    protected $ticket;

    /**
     * @ORM\Column(name="worklog_id", type="integer", nullable=true)
     */
    protected $worklog_id;

    /**
     * @ORM\Column(type="string")
     */
    protected $description;

    /**
     * @ORM\Column(type="date")
     */
    protected $day;

    /**
     * @ORM\Column(type="time")
     */
    protected $start;

    /**
     * @ORM\Column(type="time")
     */
    protected $end;

    /**
     * @ORM\Column(type="integer")
     */
    protected $duration;

    /**
     * @ORM\ManyToOne(targetEntity="Project", inversedBy="entries")
     * @ORM\JoinColumn(name="project_id", referencedColumnName="id")
     */
    protected $project;

    /**
     * @ORM\ManyToOne(targetEntity="Customer", inversedBy="entries")
     * @ORM\JoinColumn(name="customer_id", referencedColumnName="id")
     */
    protected $customer;

    /**
     * @ORM\ManyToOne(targetEntity="User", inversedBy="entries")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id")
     */
    protected $user;

    /**
     * @ORM\ManyToOne(targetEntity="Account", inversedBy="entries")
     * @ORM\JoinColumn(name="account_id", referencedColumnName="id")
     */
    protected $account;

    /**
     * @ORM\ManyToOne(targetEntity="Activity", inversedBy="entries")
     * @ORM\JoinColumn(name="activity_id", referencedColumnName="id")
     */
    protected $activity;


    /**
     * @ORM\Column(name="class", type="integer", nullable=false)
     */
    protected $class = self::CLASS_PLAIN;

    /**
     * holds summary from external ticket system; no mapping for ORM required (yet)
     *
     * @var string
     */
    protected $externalSummary = '';

    /**
     * Holds an array of labels assinged for the issue
     *
     * @var array
     */
    protected $externalLabels = array();
    
    /**
     * ID of the original booked external ticket.
     * @ORM\Column(name="internal_jira_ticket_original_key")
     *
     * @var string e.g. TYPO-1234
     */
    protected $internalJiraTicketOriginalKey = null;

    /**
     * @param string $externalReporter
     */
    public function setExternalReporter($externalReporter)
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
    public function setExternalSummary($externalSummary)
    {
        $this->externalSummary = $externalSummary;
    }

    /**
     * Returns the array of external lables.
     *
     * @return array
     */
    public function getExternalLabels()
    {
        return $this->externalLabels;
    }

    /**
     * Sets the array of external lables.
     *
     * @param array $externalLabels
     */
    public function setExternalLabels(array $arExternalLabels)
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
     * holds external reporter; no mapping for ORM required (yet)
     *
     * @var string
     */
    protected $externalReporter = '';

    public function validateDuration()
    {
        if (($this->start instanceof DateTime)
            && ($this->end instanceof DateTime)
            && ($this->end->getTimestamp() <= $this->start->getTimestamp())
        ) {
            throw new \Exception('Duration must be greater than 0!');
        }

        return $this;
    }

    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get id
     *
     * @return integer $id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Get userId
     *
     * @return integer $userId
     */
    public function getUserId()
    {
        if (! is_object($this->getUser())) {
            return null;
        }

        return $this->getUser()->getId();
    }

    /**
     * Get projectId
     *
     * @return integer $projectId
     */
    public function getProjectId()
    {
        if (! is_object($this->getProject())) {
            return null;
        }

        return $this->getProject()->getId();
    }

    /**
     * Get accountId
     *
     * @return integer $accountId
     */
    public function getAccountId()
    {
        if (! is_object($this->getAccount())) {
            return null;
        }

        return $this->getAccount()->getId();
    }

    /**
     * Get customerId
     *
     * @return integer $customerId
     */
    public function getCustomerId()
    {
        if (! is_object($this->getCustomer())) {
            return null;
        }

        return $this->getCustomer()->getId();
    }

    /**
     * Get ActivityId
     *
     * @return integer $ActivityId
     */
    public function getActivityId()
    {
        if (! is_object($this->getActivity())) {
            return null;
        }

        return $this->getActivity()->getId();
    }

    /**
     * Set ticket
     *
     * @param string $ticket
     */
    public function setTicket($ticket)
    {
        $this->ticket = str_replace(' ', '', $ticket);
        return $this;
    }

    /**
     * Get ticket
     *
     * @return string $ticket
     */
    public function getTicket()
    {
        return $this->ticket;
    }

    /**
     * Set JIRA WorklogId
     *
     * @param int $worklog_id
     */
    public function setWorklogId($worklog_id)
    {
        $this->worklog_id = $worklog_id;
        return $this;
    }

    /**
     * Get JIRA WorklogId
     *
     * @return int $worklog_id
     */
    public function getWorklogId()
    {
        return $this->worklog_id;
    }

    /**
     * Set description
     *
     * @param string $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Get description
     *
     * @return string $description
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set day
     *
     * @param string $day
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
     * Get day
     *
     * @return string $day
     */
    public function getDay()
    {
        return $this->day;
    }

    /**
     * Set start
     *
     * @param string $start
     */
    public function setStart($start)
    {
        if (!$start instanceof DateTime) {
            $start = new DateTime($start);
            list($year, $month, $day) = explode('-', $this->getDay()->format('Y-m-d'));
            $start->setDate($year, $month, $day);
        }

        $this->start = $start;
        $this->alignStartAndEnd();
        return $this;
    }

    /**
     * Get start
     *
     * @return string $start
     */
    public function getStart()
    {
        return $this->start;
    }

    /**
     * Set end
     *
     * @param string $end
     */
    public function setEnd($end)
    {
        if (!$end instanceof DateTime) {
            $end = new DateTime($end);
            list($year, $month, $day) = explode('-', $this->getDay()->format('Y-m-d'));
            $end->setDate($year, $month, $day);
        }

        $this->end = $end;
        $this->alignStartAndEnd();
        return $this;
    }

    /**
     *  Make sure end is greater or equal start
     */
    protected function alignStartAndEnd()
    {
        if (! $this->start instanceof DateTime) {
            return;
        }

        if (! $this->end instanceof DateTime) {
            return;
        }

        if ($this->end->format('H:i') < $this->start->format('H:i')) {
            $this->end = clone $this->start;
        }

        return $this;
    }

    /**
     * Get end
     *
     * @return string $end
     */
    public function getEnd()
    {
        return $this->end;
    }

    /**
     * Set duration
     *
     * @param integer $duration
     */
    public function setDuration($duration)
    {
        $this->duration = $duration;
        return $this;
    }

    /**
     * Get duration
     *
     * @return integer $duration
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * Set project
     *
     * @param Netresearch\TimeTrackerBundle\Entity\Project $project
     *
     * @return Netresearch\TimeTrackerBundle\Entity\Entry
     */
    public function setProject(\Netresearch\TimeTrackerBundle\Entity\Project $project)
    {
        $this->project = $project;
        return $this;
    }

    /**
     * Get project
     *
     * @return \Netresearch\TimeTrackerBundle\Entity\Project $project
     */
    public function getProject()
    {
        return $this->project;
    }

    /**
     * Set user
     *
     * @param Netresearch\TimeTrackerBundle\Entity\User $user
     */
    public function setUser(\Netresearch\TimeTrackerBundle\Entity\User $user)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Get user
     *
     * @return Netresearch\TimeTrackerBundle\Entity\User $user
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Set account
     *
     * @param Netresearch\TimeTrackerBundle\Entity\Account $account
     */
    public function setAccount(\Netresearch\TimeTrackerBundle\Entity\Account $account)
    {
        $this->account = $account;
        return $this;
    }

    /**
     * Get account
     *
     * @return Netresearch\TimeTrackerBundle\Entity\Account $account
     */
    public function getAccount()
    {
        return $this->account;
    }

    /**
     * Set activity
     *
     * @param Netresearch\TimeTrackerBundle\Entity\Activity $activity
     */
    public function setActivity(\Netresearch\TimeTrackerBundle\Entity\Activity $activity)
    {
        $this->activity = $activity;
        return $this;
    }

    /**
     * Get activity
     *
     * @return Netresearch\TimeTrackerBundle\Entity\Activity $activity
     */
    public function getActivity()
    {
        return $this->activity;
    }

    /**
     * Get array representation of entry object
     *
     * @return array
     */
    public function toArray()
    {
        if($this->getCustomer() != null) {
            $customer = $this->getCustomer()->getId();
        } else {
            if($this->getProject() && $this->getProject()->getCustomer()) {
                $customer = $this->getProject()->getCustomer()->getId();
            } else {
                $customer = null;
            }
        }

        return array(
            'id'            => $this->getId(),
            'date'          => $this->getDay() ? $this->getDay()->format('d/m/Y') : null,
            'start'         => $this->getStart() ? $this->getStart()->format('H:i') : null,
            'end'           => $this->getEnd() ? $this->getEnd()->format('H:i') : null,
            'user'          => $this->getUser() ? $this->getUser()->getId() : null,
            'customer'      => $customer,
            'project'       => $this->getProject() ? $this->getProject()->getId() : null,
            'activity'      => $this->getActivity() ? $this->getActivity()->getId() : null,
            'description'   => $this->getDescription(),
            'ticket'        => $this->getTicket(),
            'duration'      => $this->getDuration(),
            'class'         => $this->getClass(),
            'worklog'       => $this->getWorklogId(),
            'extTicket'     => $this->getInternalJiraTicketOriginalKey(),
        );
    }

    /**
     * Calculate difference between start and end
     *
     * @return Entry
     */
    public function calcDuration($factor = 1)
    {
        if ($this->getStart() && $this->getEnd()) {
            $start = new DateTime($this->getStart()->format('H:i'));
            $end = new DateTime($this->getEnd()->format('H:i'));

            $difference = ($end->getTimestamp() - $start->getTimestamp()) * $factor / 60;
            $this->setDuration(round($difference));
        } else {
            $this->setDuration(0);
        }
        $this->validateDuration();
        return $this;
    }

    /**
     * Set customer
     *
     * @param Netresearch\TimeTrackerBundle\Entity\Customer $customer
     */
    public function setCustomer(\Netresearch\TimeTrackerBundle\Entity\Customer $customer)
    {
        $this->customer = $customer;
        return $this;
    }

    /**
     * Get customer
     *
     * @return Netresearch\TimeTrackerBundle\Entity\Customer $customer
     */
    public function getCustomer()
    {
        return $this->customer;
    }



    /**
     * Set class
     *
     * @param integer class
     */
    public function setClass($class)
    {
        $this->class = (int) $class;
        return $this;
    }

    /**
     * Get class
     *
     * @return integer $class
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
       return array(
            'fields' => array (
                'project' =>  array(
                    'key' => $this->getProject()->getInternalJiraProjectKey()
                ),
                'summary' => $this->getTicket(),
                'description' => $this->getTicketSystemIssueLink(),
                'issuetype' => array(
                    'name' => 'Task'
                ),
            ),
        );
    }
}
