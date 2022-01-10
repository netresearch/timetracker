<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use App\Model\Base as Base;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
class Project extends Base
{
    final public const BILLING_NONE  = 0;
    final public const BILLING_TM    = 1;
    final public const BILLING_FP    = 2;
    final public const BILLING_MIXED = 3;

    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: Types::STRING)]
    protected $name;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected $active;

    #[ORM\ManyToOne(targetEntity: 'Customer', inversedBy: 'projects')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id')]
    protected $customer;

    #[ORM\Column(type: Types::BOOLEAN)]
    protected $global;

    #[ORM\Column(type: Types::STRING, name: 'jira_id')]
    protected $jiraId;

    #[ORM\ManyToOne(targetEntity: 'TicketSystem')]
    protected $ticketSystem;

    #[ORM\OneToMany(targetEntity: 'Entry', mappedBy: 'project')]
    protected $entries;

    /**
     * Estimated project duration in minutes.
     */
    #[ORM\Column(type: Types::INTEGER, name: 'estimation')]
    protected $estimation;
    /**
     * Offer number.
     */
    #[ORM\Column(name: 'offer', length: 31)]
    protected $offer;
    /**
     * Used billing method.
     */
    #[ORM\Column(type: Types::INTEGER, name: 'billing')]
    protected $billing;
    /**
     * cost center (number or name).
     */
    #[ORM\Column(name: 'cost_center', length: 31, nullable: true)]
    protected $costCenter;
    /**
     * internal reference number.
     */
    #[ORM\Column(name: 'internal_ref', length: 31, nullable: true)]
    protected $internalReference;
    /**
     * external (clients) reference number.
     */
    #[ORM\Column(name: 'external_ref', length: 31, nullable: true)]
    protected $externalReference;
    /**
     * project manager user.
     */
    #[ORM\ManyToOne(targetEntity: 'User')]
    #[ORM\JoinColumn(name: 'project_lead_id', referencedColumnName: 'id', nullable: true)]
    protected $projectLead;
    /**
     * lead developer.
     */
    #[ORM\ManyToOne(targetEntity: 'User')]
    #[ORM\JoinColumn(name: 'technical_lead_id', referencedColumnName: 'id', nullable: true)]
    protected $technicalLead;
    /**
     * invoice number, reserved for future use.
     *
     * * @ORM\Column(name="invoice", length=31, nullable=true)
     */
    protected $invoice;
    #[ORM\Column(name: 'additional_information_from_external', type: Types::BOOLEAN)]
    protected $additionalInformationFromExternal;
    /**
     * the internal key of the project the current ticket should be booked to.
     */
    #[ORM\Column(name: 'internal_jira_project_key')]
    protected $internalJiraProjectKey;
    /**
     * the id of the internal jira ticket system.
     */
    #[ORM\Column(name: 'internal_jira_ticket_system')]
    protected $internalJiraTicketSystem;

    /**
     * Sets the additional Information.
     *
     * @param bool $additionalInformationFromExternal
     *
     * @return $this
     */
    public function setAdditionalInformationFromExternal($additionalInformationFromExternal)
    {
        $this->additionalInformationFromExternal = $additionalInformationFromExternal;

        return $this;
    }

    /**
     * Sets the internal Jira project key.
     *
     * @param string $strInternalJiraProjectKey the internal jira project key
     *
     * @return $this
     */
    public function setInternalJiraProjectKey($strInternalJiraProjectKey)
    {
        $this->internalJiraProjectKey = $strInternalJiraProjectKey;

        return $this;
    }

    /**
     * Sets the id internal Jira ticket system.
     *
     * @param string $nInternalJiraTicketSystem the id of internal jira ticketsystem
     *
     * @return $this
     */
    public function setInternalJiraTicketSystem($nInternalJiraTicketSystem)
    {
        $this->internalJiraTicketSystem = $nInternalJiraTicketSystem;

        return $this;
    }

    /**
     * @return bool
     */
    public function getAdditionalInformationFromExternal()
    {
        return $this->additionalInformationFromExternal;
    }

    public function __construct()
    {
        $this->entries = new ArrayCollection();
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
     * Set id.
     *
     * @param int $id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Set name.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     *
     * @return string $name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set active.
     *
     * @param bool $active
     *
     * @return $this
     */
    public function setActive($active)
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Get active.
     *
     * @return bool $active
     */
    public function getActive()
    {
        return $this->active;
    }

    /**
     * Set customer.
     *
     * @return $this
     */
    public function setCustomer(Customer $customer)
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * Get customer.
     *
     * @return Customer $customer
     */
    public function getCustomer()
    {
        return $this->customer;
    }

    /**
     * Set global.
     *
     * @param bool $global
     *
     * @return $this
     */
    public function setGlobal($global)
    {
        $this->global = $global;

        return $this;
    }

    /**
     * Get global.
     *
     * @return bool $global
     */
    public function getGlobal()
    {
        return $this->global;
    }

    /**
     * Add entries.
     *
     * @return Project
     */
    public function addEntries(Entry $entry)
    {
        $this->entries[] = $entry;

        return $this;
    }

    /**
     * Get entries.
     *
     * @return Collection $entries
     */
    public function getEntries()
    {
        return $this->entries;
    }

    public function getJiraId()
    {
        return $this->jiraId;
    }

    public function setJiraId($jiraId)
    {
        $this->jiraId = $jiraId;

        return $this;
    }

    /**
     * @return TicketSystem $ticketSystem
     */
    public function getTicketSystem()
    {
        return $this->ticketSystem;
    }

    /**
     * Set the id of the ticket system that is associated with this project.
     *
     * @param TicketSystem $ticketSystem
     *
     * @return Project
     */
    public function setTicketSystem($ticketSystem)
    {
        $this->ticketSystem = $ticketSystem;

        return $this;
    }

    public function getEstimation()
    {
        return $this->estimation;
    }

    public function setEstimation($estimation)
    {
        $this->estimation = $estimation;

        return $this;
    }

    public function getOffer()
    {
        return $this->offer;
    }

    public function setOffer($offer)
    {
        $this->offer = $offer;

        return $this;
    }

    public function getBilling()
    {
        return $this->billing;
    }

    public function setBilling($billing)
    {
        $this->billing = $billing;

        return $this;
    }

    public function getCostCenter()
    {
        return $this->costCenter;
    }

    public function setCostCenter($costCenter)
    {
        $this->costCenter = $costCenter;

        return $this;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function setInvoice($invoice)
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function setProjectLead($projectLead)
    {
        $this->projectLead = $projectLead;

        return $this;
    }

    public function getProjectLead()
    {
        return $this->projectLead;
    }

    public function setTechnicalLead($technicalLead)
    {
        $this->technicalLead = $technicalLead;

        return $this;
    }

    public function getTechnicalLead()
    {
        return $this->technicalLead;
    }

    /**
     * Set internalReference.
     *
     * @param string $internalReference
     *
     * @return Project
     */
    public function setInternalReference($internalReference)
    {
        $this->internalReference = $internalReference;

        return $this;
    }

    /**
     * Get internalReference.
     *
     * @return string
     */
    public function getInternalReference()
    {
        return $this->internalReference;
    }

    /**
     * Set externalReference.
     *
     * @param string $externalReference
     *
     * @return Project
     */
    public function setExternalReference($externalReference)
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    /**
     * Get externalReference.
     *
     * @return string
     */
    public function getExternalReference()
    {
        return $this->externalReference;
    }

    /**
     * Add entries.
     *
     * @return Project
     */
    public function addEntry(Entry $entries)
    {
        $this->entries[] = $entries;

        return $this;
    }

    /**
     * Remove entries.
     */
    public function removeEntrie(Entry $entries): void
    {
        $this->entries->removeElement($entries);
    }

    /**
     * Add entries.
     *
     * @return Project
     */
    public function addEntrie(Entry $entries)
    {
        $this->entries[] = $entries;

        return $this;
    }

    /**
     * Returns the current defined InternalJiraProjectKey.
     *
     * @return mixed e.g. OPSA
     */
    public function getInternalJiraProjectKey()
    {
        return $this->internalJiraProjectKey;
    }

    /**
     * Returns true, if a internJiraProjectKey is configured.
     *
     * @return bool
     */
    public function hasInternalJiraProjectKey()
    {
        return !empty($this->internalJiraProjectKey);
    }

    /**
     * Returns the id of the internal JIRA ticket system.
     *
     * @return mixed
     */
    public function getInternalJiraTicketSystem()
    {
        return $this->internalJiraTicketSystem;
    }

    /**
     * Returns true, if the passed project key matches the configured internal project key.
     *
     * @param string $projectKey
     *
     * @return bool
     */
    public function matchesInternalProject($projectKey)
    {
        return $projectKey === $this->getInternalJiraProjectKey();
    }
}
