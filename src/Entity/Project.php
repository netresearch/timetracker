<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BillingType;
use App\Model\Base;
use App\Service\Util\TimeCalculationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Override;

use function in_array;

#[ORM\Entity(repositoryClass: \App\Repository\ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
class Project extends Base
{
    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: 'string', length: 127)]
    protected string $name = '';

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected bool $active = false;

    /**
     * @var Customer|null
     */
    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'projects')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id')]
    protected $customer;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected bool $global = false;

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', name: 'jira_id', length: 63, nullable: true)]
    protected $jiraId;

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', name: 'jira_ticket', length: 255, nullable: true)]
    protected $jiraTicket;

    /**
     * Ticket numbers that are subtickets of $jiraTicket
     * Gets calculated automatically.
     * Comma-separated string.
     *
     * @var string|null
     */
    #[ORM\Column(type: 'string', name: 'subtickets', length: 255, nullable: true)]
    protected $subtickets;

    /**
     * @var TicketSystem|null
     */
    #[ORM\ManyToOne(targetEntity: TicketSystem::class)]
    #[ORM\JoinColumn(name: 'ticket_system', referencedColumnName: 'id')]
    protected $ticketSystem;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Entry>
     */
    #[ORM\OneToMany(targetEntity: Entry::class, mappedBy: 'project')]
    protected $entries;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Preset>
     */
    #[ORM\OneToMany(targetEntity: Preset::class, mappedBy: 'project')]
    protected $presets;

    /**
     * Estimated project duration in minutes.
     */
    #[ORM\Column(type: 'integer', name: 'estimation', options: ['default' => 0])]
    protected int $estimation = 0;

    /**
     * Offer number.
     */
    #[ORM\Column(name: 'offer', length: 31, nullable: true)]
    protected ?string $offer = null;

    /**
     * Used billing method.
     */
    #[ORM\Column(type: 'smallint', name: 'billing', nullable: true, options: ['default' => 0], enumType: BillingType::class)]
    protected ?BillingType $billing = BillingType::NONE;

    /**
     * cost center (number or name).
     */
    #[ORM\Column(name: 'cost_center', length: 31, nullable: true)]
    protected ?string $costCenter = null;

    /**
     * internal reference number.
     *
     * @var string|null
     */
    #[ORM\Column(name: 'internal_ref', length: 31, nullable: true)]
    protected $internalReference;

    /**
     * external (clients) reference number.
     *
     * @var string|null
     */
    #[ORM\Column(name: 'external_ref', length: 31, nullable: true)]
    protected $externalReference;

    /**
     * project manager user.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'project_lead_id', referencedColumnName: 'id', nullable: true)]
    protected ?User $projectLead = null;

    /**
     * lead developer.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'technical_lead_id', referencedColumnName: 'id', nullable: true)]
    protected ?User $technicalLead = null;

    /**
     * invoice number, reserved for future use.
     */
    #[ORM\Column(name: 'invoice', length: 31, nullable: true)]
    protected ?string $invoice = null;

    #[ORM\Column(name: 'additional_information_from_external', type: 'boolean', options: ['default' => false])]
    protected bool $additionalInformationFromExternal = false;

    /**
     * the internal key of the project the current ticket should be booked to.
     *
     * @var string|null
     */
    #[ORM\Column(name: 'internal_jira_project_key', type: 'string', length: 255, nullable: true)]
    protected $internalJiraProjectKey;

    /**
     * the id of the internal jira ticket system.
     *
     * @var string|null
     */
    #[ORM\Column(name: 'internal_jira_ticket_system', type: 'string', length: 255, nullable: true)]
    protected $internalJiraTicketSystem;

    /**
     * Sets the additional Information.
     *
     * @return $this
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
     *
     * @return $this
     */
    public function setInternalJiraProjectKey(?string $strInternalJiraProjectKey): static
    {
        $this->internalJiraProjectKey = $strInternalJiraProjectKey;

        return $this;
    }

    /**
     * Sets the id internal Jira ticket system.
     *
     * @param string|null $nInternalJiraTicketSystem the id of internal jira ticketsystem
     *
     * @return $this
     */
    public function setInternalJiraTicketSystem(?string $nInternalJiraTicketSystem): static
    {
        // Normalize empty string to null for nullable DB column
        if ('' === $nInternalJiraTicketSystem || null === $nInternalJiraTicketSystem) {
            $this->internalJiraTicketSystem = null;
        } else {
            $this->internalJiraTicketSystem = $nInternalJiraTicketSystem;
        }

        return $this;
    }

    public function getAdditionalInformationFromExternal(): ?bool
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
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['estimationText'] = new TimeCalculationService()->minutesToReadable($this->getEstimation(), false);

        return $data;
    }

    /**
     * Retrieve the id from the object, useful for hydration, etc.
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set the id of the object, useful for hydration, etc.
     */
    public function setId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Returns the project's name.
     *
     * @return string the name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Sets the project's name.
     *
     * @param string $name the name to be set
     *
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Returns the project's active status.
     *
     * @return bool active or not
     */
    public function getActive(): bool
    {
        return $this->active;
    }

    /**
     * Sets the project's active status.
     *
     * @param bool $active the status to be set
     *
     * @return $this
     */
    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Returns the project's customer.
     *
     * @return Customer|null the customer
     */
    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    /**
     * Sets the project's customer.
     *
     * @param Customer|null $customer the customer to be set
     *
     * @return $this
     */
    public function setCustomer(?Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * Returns the project's global status.
     *
     * @return bool global or not
     */
    public function getGlobal(): bool
    {
        return $this->global;
    }

    /**
     * Sets the project's global status.
     *
     * @param bool $global the status to be set
     *
     * @return $this
     */
    public function setGlobal(bool $global): static
    {
        $this->global = $global;

        return $this;
    }

    /**
     * Returns the project's jira ID.
     *
     * @return string|null the jira ID
     */
    public function getJiraId(): ?string
    {
        return $this->jiraId;
    }

    /**
     * Sets the project's Jira ID.
     *
     * @param string|null $jiraId the Jira ID to be set
     *
     * @return $this
     */
    public function setJiraId(?string $jiraId): static
    {
        $this->jiraId = $jiraId;

        return $this;
    }

    /**
     * Returns the project's jira ticket.
     *
     * @return string|null the jira ticket
     */
    public function getJiraTicket(): ?string
    {
        return $this->jiraTicket;
    }

    /**
     * Sets the project's jira ticket.
     *
     * @param string|null $jiraTicket the jira ticket to be set
     *
     * @return $this
     */
    public function setJiraTicket(?string $jiraTicket): static
    {
        $this->jiraTicket = $jiraTicket;

        return $this;
    }

    /**
     * Returns the project's subtickets.
     *
     * @return string|null the subtickets
     */
    public function getSubtickets(): ?string
    {
        return $this->subtickets;
    }

    /**
     * Sets the project's subtickets.
     *
     * @param string|null $subtickets the subtickets to be set
     *
     * @return $this
     */
    public function setSubtickets(?string $subtickets): static
    {
        $this->subtickets = $subtickets;

        return $this;
    }

    /**
     * Returns the project's ticketSystem.
     *
     * @return TicketSystem|null the ticketSystem
     */
    public function getTicketSystem(): ?TicketSystem
    {
        return $this->ticketSystem;
    }

    /**
     * Sets the project's ticketSystem.
     *
     * @param TicketSystem|null $ticketSystem the ticketSystem to be set
     *
     * @return $this
     */
    public function setTicketSystem(?TicketSystem $ticketSystem): static
    {
        $this->ticketSystem = $ticketSystem;

        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Entry>
     */
    public function getEntries(): \Doctrine\Common\Collections\Collection
    {
        return $this->entries;
    }

    /**
     * Returns the project's estimated duration.
     *
     * @return int the estimation in minutes
     */
    public function getEstimation(): int
    {
        return $this->estimation;
    }

    /**
     * Sets the project's estimated duration.
     *
     * @param int $estimation the estimation in minutes
     *
     * @return $this
     */
    public function setEstimation(int $estimation): static
    {
        $this->estimation = $estimation;

        return $this;
    }

    /**
     * Returns the project's offer number.
     *
     * @return string|null the offer number
     */
    public function getOffer(): ?string
    {
        return $this->offer;
    }

    /**
     * Sets the project's offer number.
     *
     * @param string|null $offer the offer number
     *
     * @return $this
     */
    public function setOffer(?string $offer): static
    {
        $this->offer = $offer;

        return $this;
    }

    /**
     * Returns the project's billing method.
     *
     * @return BillingType the billing method
     */
    public function getBilling(): BillingType
    {
        return $this->billing ?? BillingType::NONE;
    }

    /**
     * Sets the project's billing method.
     *
     * @param BillingType $billingType the billing method
     *
     * @return $this
     */
    public function setBilling(BillingType $billingType): static
    {
        $this->billing = $billingType;

        return $this;
    }

    /**
     * Returns the project's cost center.
     *
     * @return string|null the cost center
     */
    public function getCostCenter(): ?string
    {
        return $this->costCenter;
    }

    /**
     * Sets the project's cost center.
     *
     * @param string|null $costCenter the cost center
     *
     * @return $this
     */
    public function setCostCenter(?string $costCenter): static
    {
        $this->costCenter = $costCenter;

        return $this;
    }

    /**
     * Returns the project's internal reference number.
     *
     * @return string|null the internal reference number
     */
    public function getInternalReference(): ?string
    {
        return $this->internalReference;
    }

    /**
     * Sets the project's internal reference number.
     *
     * @param string|null $internalReference the internal reference number
     *
     * @return $this
     */
    public function setInternalReference(?string $internalReference): static
    {
        $this->internalReference = $internalReference;

        return $this;
    }

    /**
     * Returns the project's external reference number.
     *
     * @return string|null the external reference number
     */
    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    /**
     * Sets the project's external reference number.
     *
     * @param string|null $externalReference the external reference number
     *
     * @return $this
     */
    public function setExternalReference(?string $externalReference): static
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    /**
     * Returns the project lead user.
     *
     * @return User|null the project lead
     */
    public function getProjectLead(): ?User
    {
        return $this->projectLead;
    }

    /**
     * Sets the project lead user.
     *
     * @param User|null $user the project lead
     *
     * @return $this
     */
    public function setProjectLead(?User $user): static
    {
        $this->projectLead = $user;

        return $this;
    }

    /**
     * Returns the technical lead user.
     *
     * @return User|null the technical lead
     */
    public function getTechnicalLead(): ?User
    {
        return $this->technicalLead;
    }

    /**
     * Sets the technical lead user.
     *
     * @param User|null $user the technical lead
     *
     * @return $this
     */
    public function setTechnicalLead(?User $user): static
    {
        $this->technicalLead = $user;

        return $this;
    }

    /**
     * Returns the project's invoice number.
     *
     * @return string|null the invoice number
     */
    public function getInvoice(): ?string
    {
        return $this->invoice;
    }

    /**
     * Sets the project's invoice number.
     *
     * @param string|null $invoice the invoice number
     *
     * @return $this
     */
    public function setInvoice(?string $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    /**
     * Get internal Jira project key.
     *
     * @return string|null $internalJiraProjectKey
     */
    public function getInternalJiraProjectKey(): ?string
    {
        return $this->internalJiraProjectKey;
    }

    /**
     * Get internal Jira ticket system.
     *
     * @return string|null $internalJiraTicketSystem
     */
    public function getInternalJiraTicketSystem(): ?string
    {
        return null !== $this->internalJiraTicketSystem ? (string) $this->internalJiraTicketSystem : null;
    }

    /**
     * Check if this project has an internal JIRA project key configured.
     */
    public function hasInternalJiraProjectKey(): bool
    {
        return null !== $this->internalJiraProjectKey && '' !== $this->internalJiraProjectKey
            && null !== $this->internalJiraTicketSystem && '' !== $this->internalJiraTicketSystem;
    }

    /**
     * Check if a given JIRA project key matches the internal JIRA project configuration.
     */
    public function matchesInternalJiraProject(string $jiraId): bool
    {
        if (! $this->hasInternalJiraProjectKey()) {
            return false;
        }

        $internalKey = $this->getInternalJiraProjectKey();
        if (null === $internalKey) {
            return false;
        }

        // Support comma-separated list of project keys
        $projectKeys = array_map('trim', explode(',', $internalKey));

        return in_array($jiraId, $projectKeys, true);
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Preset>
     */
    public function getPresets(): \Doctrine\Common\Collections\Collection
    {
        return $this->presets;
    }
}
