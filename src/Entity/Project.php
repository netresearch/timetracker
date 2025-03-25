<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

use App\Helper\TimeHelper;
use App\Model\Base;

/**
 *
 * @ORM\Entity(repositoryClass="App\Repository\ProjectRepository")
 * @ORM\Table(name="projects")
 */
class Project extends Base
{

    const BILLING_NONE  = 0;

    const BILLING_TM    = 1;

    const BILLING_FP    = 2;

    const BILLING_MIXED = 3;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string")
     */
    protected $name;

    /**
     * @ORM\Column(type="boolean")
     */
    protected $active;

    /**
     * @ORM\ManyToOne(targetEntity="Customer", inversedBy="projects")
     * @ORM\JoinColumn(name="customer_id", referencedColumnName="id")
     */
    protected $customer;

    /**
     * @ORM\Column(type="boolean")
     */
    protected $global;


    /**
     * @ORM\Column(type="string", name="jira_id", nullable=true)
     */
    protected $jiraId;

    /**
     * @ORM\Column(type="string", name="jira_ticket", length=255, nullable=true)
     */
    protected $jiraTicket;

    /**
     * Ticket numbers that are subtickets of $jiraTicket
     * Gets calculated automatically.
     * Comma-separated string.
     *
     * @ORM\Column(type="string", name="subtickets", length=255, nullable=true)
     */
    protected $subtickets;

    /**
     * @ORM\ManyToOne(targetEntity="TicketSystem")
     * @ORM\JoinColumn(name="ticket_system", referencedColumnName="id")
     */
    protected $ticketSystem;

    /**
     * @ORM\OneToMany(targetEntity="Entry", mappedBy="project")
     */
    protected $entries;

    /**
     * @ORM\OneToMany(targetEntity="Preset", mappedBy="project")
     */
    protected $presets;

    /**
     * Estimated project duration in minutes
     * @ORM\Column(type="integer", name="estimation")
     */
    protected $estimation;

    /**
     * Offer number
     * @ORM\Column(name="offer", length=31)
     */
    protected $offer;

    /**
     * Used billing method
     * @ORM\Column(type="integer", name="billing")
     */
    protected $billing;

    /**
     * cost center (number or name)
     * @ORM\Column(name="cost_center", length=31, nullable=true)
     */
    protected $costCenter;

    /**
     * internal reference number
     * @ORM\Column(name="internal_ref", length=31, nullable=true)
     */
    protected $internalReference;

    /**
     * external (clients) reference number
     * @ORM\Column(name="external_ref", length=31, nullable=true)
     */
    protected $externalReference;

    /**
     * project manager user
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="project_lead_id", referencedColumnName="id", nullable=true)
     */
    protected $projectLead;

    /**
     * lead developer
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="technical_lead_id", referencedColumnName="id", nullable=true)
     */
    protected $technicalLead;

    /**
     * invoice number, reserved for future use
     * * @ORM\Column(name="invoice", length=31, nullable=true)
     */
    protected $invoice;

    /**
     * @ORM\Column(name="additional_information_from_external", type="boolean")
     */
    protected $additionalInformationFromExternal;

    /**
     * the internal key of the project the current ticket should be booked to.
     *
     * @ORM\Column(name="internal_jira_project_key", type="string", length=255, nullable=true)
     */
    protected $internalJiraProjectKey;


    /**
     * the id of the internal jira ticket system
     *
     * @ORM\Column(name="internal_jira_ticket_system", type="string", length=255, nullable=true)
     */
    protected $internalJiraTicketSystem;

    /**
     * Sets the additional Information.
     *
     * @param boolean $additionalInformationFromExternal
     *
     * @return $this
     */
    public function setAdditionalInformationFromExternal($additionalInformationFromExternal): static
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
    public function setInternalJiraProjectKey($strInternalJiraProjectKey): static
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
    public function setInternalJiraTicketSystem($nInternalJiraTicketSystem): static
    {
        $this->internalJiraTicketSystem = $nInternalJiraTicketSystem;

        return $this;
    }


    /**
     * @return boolean
     */
    public function getAdditionalInformationFromExternal()
    {
        return $this->additionalInformationFromExternal;
    }


    public function __construct()
    {
        $this->entries = new ArrayCollection();
        $this->presets = new ArrayCollection();
    }

    /**
     * Convert to array and add additional properties.
     */
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['estimationText'] = TimeHelper::minutes2readable($this->getEstimation(), false);
        return $data;
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
     * Set id
     * @param integer $id
     *
     * @return $this
     */
    public function setId($id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Set name
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName($name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get name
     *
     * @return string $name
     */
    public function getName()
    {
        return $this->name;
    }



    /**
     * Set active
     *
     * @param boolean $active
     *
     * @return $this
     */
    public function setActive($active): static
    {
        $this->active = $active;
        return $this;
    }

    /**
     * Get active
     *
     * @return boolean $active
     */
    public function getActive()
    {
        return $this->active;
    }



    /**
     * Set customer
     *
     *
     * @return $this
     */
    public function setCustomer(Customer $customer): static
    {
        $this->customer = $customer;
        return $this;
    }

    /**
     * Get customer
     *
     * @return Customer $customer
     */
    public function getCustomer()
    {
        return $this->customer;
    }




    /**
     * Set global
     *
     * @param boolean $global
     *
     * @return $this
     */
    public function setGlobal($global): static
    {
        $this->global = $global;
        return $this;
    }

    /**
     * Get global
     *
     * @return boolean $global
     */
    public function getGlobal()
    {
        return $this->global;
    }


    /**
     * Get entries
     *
     * @return \Doctrine\Common\Collections\Collection $entries
     */
    public function getEntries()
    {
        return $this->entries;
    }

    public function getJiraId()
    {
        return $this->jiraId;
    }

    public function setJiraId($jiraId): static
    {
        $this->jiraId = $jiraId;
        return $this;
    }

    public function getJiraTicket()
    {
        return $this->jiraTicket;
    }

    public function setJiraTicket($jiraTicket): static
    {
        if ($jiraTicket === '') {
            $jiraTicket = null;
        }

        $this->jiraTicket = $jiraTicket;
        return $this;
    }

    public function getSubtickets()
    {
        if ($this->subtickets == '') {
            return [];
        }

        return explode(',', (string) $this->subtickets);
    }

    public function setSubtickets($subtickets): static
    {
        if (is_array($subtickets)) {
            $subtickets = implode(',', $subtickets);
        }

        $this->subtickets = $subtickets;
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
     * Set the id of the ticket system that is associated with this project
     * @param TicketSystem $ticketSystem
     */
    public function setTicketSystem($ticketSystem): static
    {
        $this->ticketSystem = $ticketSystem;
        return $this;
    }


    public function getEstimation()
    {
        return $this->estimation;
    }

    public function setEstimation($estimation): static
    {
        $this->estimation = $estimation;
        return $this;
    }

    public function getOffer()
    {
        return $this->offer;
    }

    public function setOffer($offer): static
    {
        $this->offer = $offer;
        return $this;
    }

    public function getBilling()
    {
        return $this->billing;
    }

    public function setBilling($billing): static
    {
        $this->billing = $billing;
        return $this;
    }

    public function getCostCenter()
    {
        return $this->costCenter;
    }

    public function setCostCenter($costCenter): static
    {
        $this->costCenter = $costCenter;
        return $this;
    }

    public function getInvoice()
    {
        return $this->invoice;
    }

    public function setInvoice($invoice): static
    {
        $this->invoice = $invoice;
        return $this;
    }

    public function setProjectLead($projectLead): static
    {
        $this->projectLead = $projectLead;
        return $this;
    }

    public function getProjectLead()
    {
        return $this->projectLead;
    }

    public function setTechnicalLead($technicalLead): static
    {
        $this->technicalLead = $technicalLead;
        return $this;
    }

    public function getTechnicalLead()
    {
        return $this->technicalLead;
    }


    /**
     * Set internalReference
     *
     * @param string $internalReference
     */
    public function setInternalReference($internalReference): static
    {
        $this->internalReference = $internalReference;

        return $this;
    }

    /**
     * Get internalReference
     *
     * @return string
     */
    public function getInternalReference()
    {
        return $this->internalReference;
    }

    /**
     * Set externalReference
     *
     * @param string $externalReference
     */
    public function setExternalReference($externalReference): static
    {
        $this->externalReference = $externalReference;
        return $this;
    }

    /**
     * Get externalReference
     *
     * @return string
     */
    public function getExternalReference()
    {
        return $this->externalReference;
    }

    /**
     * Add entry
     */
    public function addEntry(Entry $entry): static
    {
        $this->entries[] = $entry;
        return $this;
    }

    /**
     * Remove entry
     */
    public function removeEntry(Entry $entry): void
    {
        $this->entries->removeElement($entry);
    }

    /**
     * Returns the current defined InternalJiraProjectKey
     *
     * @return mixed e.g. OPSA
     */
    public function getInternalJiraProjectKey()
    {
        return $this->internalJiraProjectKey;
    }

    /**
     * Returns true, if a internJiraProjectKey is configured.
     */
    public function hasInternalJiraProjectKey(): bool
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
     */
    public function matchesInternalJiraProject($projectKey): bool
    {
        return $projectKey === $this->getInternalJiraProjectKey();
    }

    /**
     * Get presets
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getPresets()
    {
        return $this->presets;
    }

    /**
     * Add preset
     */
    public function addPreset(Preset $preset): static
    {
        $this->presets[] = $preset;
        return $this;
    }

    /**
     * Remove preset
     */
    public function removePreset(Preset $preset): void
    {
        $this->presets->removeElement($preset);
    }

}
