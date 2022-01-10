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
     */
    #[ORM\Column(name: 'invoice', length: 31, nullable: true)]
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
     */
    public function setAdditionalInformationFromExternal(bool $additionalInformationFromExternal): static
    {
        $this->additionalInformationFromExternal = $additionalInformationFromExternal;

        return $this;
    }

    /**
     * Sets the internal Jira project key.
     *
     * @param string $strInternalJiraProjectKey the internal jira project key
     */
    public function setInternalJiraProjectKey(string $strInternalJiraProjectKey): static
    {
        $this->internalJiraProjectKey = $strInternalJiraProjectKey;

        return $this;
    }

    /**
     * Sets the id internal Jira ticket system.
     *
     * @param string $nInternalJiraTicketSystem the id of internal jira ticketsystem
     */
    public function setInternalJiraTicketSystem(string $nInternalJiraTicketSystem): static
    {
        $this->internalJiraTicketSystem = $nInternalJiraTicketSystem;

        return $this;
    }

    public function getAdditionalInformationFromExternal(): bool
    {
        return $this->additionalInformationFromExternal;
    }

    public function __construct()
    {
        $this->entries = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getActive(): ?bool
    {
        return $this->active;
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

    public function setGlobal(bool $global): static
    {
        $this->global = $global;

        return $this;
    }

    public function getGlobal(): ?bool
    {
        return $this->global;
    }

    public function addEntries(Entry $entry): static
    {
        $this->entries[] = $entry;

        return $this;
    }

    public function getEntries(): Collection
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

    public function getTicketSystem(): ?TicketSystem
    {
        return $this->ticketSystem;
    }

    /**
     * Set the id of the ticket system that is associated with this project.
     */
    public function setTicketSystem(?TicketSystem $ticketSystem): static
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
    public function setInternalReference(string $internalReference): self
    {
        $this->internalReference = $internalReference;

        return $this;
    }

    /**
     * Get internalReference.
     *
     * @return string
     */
    public function getInternalReference(): string
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
    public function setExternalReference(string $externalReference): self
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    /**
     * Get externalReference.
     *
     * @return string
     */
    public function getExternalReference(): string
    {
        return $this->externalReference;
    }

    /**
     * Add entries.
     *
     * @return Project
     */
    public function addEntry(Entry $entries): self
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
    public function addEntrie(Entry $entries): self
    {
        $this->entries[] = $entries;

        return $this;
    }

    /**
     * Returns the current defined InternalJiraProjectKey.
     *
     * @return mixed e.g. OPSA
     */
    public function getInternalJiraProjectKey(): mixed
    {
        return $this->internalJiraProjectKey;
    }

    /**
     * Returns true, if a internJiraProjectKey is configured.
     *
     * @return bool
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
    public function getInternalJiraTicketSystem(): mixed
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
    public function matchesInternalProject(string $projectKey): bool
    {
        return $projectKey === $this->getInternalJiraProjectKey();
    }
}
