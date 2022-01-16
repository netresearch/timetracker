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

    #[ORM\Column(type: Types::STRING, options: ["default" => ''])]
    protected $name = '';

    #[ORM\Column(type: Types::BOOLEAN, options: ["default" => 1])]
    protected $active = true;

    #[ORM\ManyToOne(targetEntity: 'Customer', inversedBy: 'projects')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id')]
    protected $customer;

    #[ORM\Column(type: Types::BOOLEAN, options: ["default" => 1])]
    protected $global = true;

    #[ORM\Column(type: Types::STRING, name: 'jira_id', options: ["default" => ''])]
    protected $jiraId = '';

    #[ORM\ManyToOne(targetEntity: 'TicketSystem')]
    protected $ticketSystem;

    #[ORM\OneToMany(targetEntity: 'Entry', mappedBy: 'project')]
    protected $entries;

    /**
     * Estimated project duration in minutes.
     */
    #[ORM\Column(type: Types::INTEGER, name: 'estimation', options: ["default" => 0])]
    protected $estimation = 0;

    /**
     * Offer number.
     */
    #[ORM\Column(name: 'offer', length: 31, options: ["default" => ''])]
    protected $offer = '';

    /**
     * Used billing method.
     */
    #[ORM\Column(type: Types::INTEGER, name: 'billing', options: ["default" => Project::BILLING_NONE])]
    protected $billing = 0;

    /**
     * cost center (number or name).
     */
    #[ORM\Column(name: 'cost_center', length: 31, options: ["default" => ''])]
    protected $costCenter = '';

    /**
     * internal reference number.
     */
    #[ORM\Column(name: 'internal_ref', length: 31, options: ["default" => ''])]
    protected $internalReference = '';

    /**
     * external (clients) reference number.
     */
    #[ORM\Column(name: 'external_ref', length: 31, options: ["default" => ''])]
    protected $externalReference = '';

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
    #[ORM\Column(name: 'invoice', length: 31, options: ["default" => ''])]
    protected $invoice = '';

    #[ORM\Column(name: 'additional_information_from_external', type: Types::BOOLEAN, options: ["default" => 0])]
    protected $additionalInformationFromExternal = 0;

    /**
     * the internal key of the project the current ticket should be booked to.
     */
    #[ORM\Column(name: 'internal_jira_project_key', options: ["default" => ''])]
    protected $internalJiraProjectKey = '';

    /**
     * the id of the internal jira ticket system.
     */
    #[ORM\Column(name: 'internal_jira_ticket_system', nullable: true)]
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
    public function setInternalJiraTicketSystem(int $nInternalJiraTicketSystem): static
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

    public function setInternalReference(string $internalReference): static
    {
        $this->internalReference = $internalReference;

        return $this;
    }

    public function getInternalReference(): ?string
    {
        return $this->internalReference;
    }

    public function setExternalReference(string $externalReference): static
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function addEntry(Entry $entries): static
    {
        $this->entries[] = $entries;

        return $this;
    }

    public function removeEntrie(Entry $entries): void
    {
        $this->entries->removeElement($entries);
    }

    public function addEntrie(Entry $entries): static
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
