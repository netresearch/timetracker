<?php
declare(strict_types=1);

namespace App\Entity;

use App\Service\Util\TimeCalculationService;
use App\Model\Base;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\ProjectRepository::class)]
#[ORM\Table(name: 'projects')]
class Project extends Base
{
    public const BILLING_NONE = 0;

    public const BILLING_TM = 1;

    public const BILLING_FP = 2;

    public const BILLING_MIXED = 3;

    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 127)]
    protected string $name = '';

    /**
     * @var bool
     */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    protected bool $active = false;

    /**
     * @var Customer|null
     */
    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'projects')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id')]
    protected $customer;

    /**
     * @var bool
     */
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
    #[ORM\Column(type: 'integer', name: 'estimation', nullable: true)]
    protected ?int $estimation = null;

    /**
     * Offer number.
     */
    #[ORM\Column(name: 'offer', length: 31, nullable: true)]
    protected ?string $offer = null;

    /**
     * Used billing method.
     */
    #[ORM\Column(type: 'smallint', name: 'billing', nullable: true, options: ['default' => 0])]
    protected ?int $billing = 0;

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

    /**
     * @var bool
     */
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
     * @param bool $additionalInformationFromExternal
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
     * @param string|null $nInternalJiraTicketSystem the id of internal jira ticketsystem
     *
     * @return $this
     */
    public function setInternalJiraTicketSystem($nInternalJiraTicketSystem): static
    {
        // Normalize empty string to null for nullable DB column
        if ('' === $nInternalJiraTicketSystem || null === $nInternalJiraTicketSystem) {
            $this->internalJiraTicketSystem = null;
        } else {
            $this->internalJiraTicketSystem = (string) $nInternalJiraTicketSystem;
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
    public function toArray(): array
    {
        $data = parent::toArray();
        $data['estimationText'] = (new TimeCalculationService())->minutesToReadable($this->getEstimation() ?? 0, false);

        return $data;
    }

    /**
     * Get id.
     *
     * @return int|null $id
     */
    public function getId(): ?int
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
    public function setId($id): static
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
    public function setName($name): static
    {
        if (null === $name) {
            $name = '';
        }
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     *
     * @return string|null $name
     */
    public function getName(): ?string
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
    public function setActive($active): static
    {
        $this->active = $active;

        return $this;
    }

    /**
     * Get active.
     *
     * @return bool|null $active
     */
    public function getActive(): ?bool
    {
        return $this->active;
    }

    /**
     * Set customer.
     *
     * @return $this
     */
    public function setCustomer(Customer $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * Get customer.
     *
     * @return Customer|null $customer
     */
    public function getCustomer(): ?Customer
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
    public function setGlobal($global): static
    {
        $this->global = (bool) $global;

        return $this;
    }

    /**
     * Get global.
     *
     * @return bool|null $global
     */
    public function getGlobal(): ?bool
    {
        return $this->global;
    }

    /**
     * Get entries.
     *
     * @return \Doctrine\Common\Collections\Collection $entries
     *
     * @psalm-return \Doctrine\Common\Collections\Collection<int, Entry>
     */
    public function getEntries(): \Doctrine\Common\Collections\Collection
    {
        return $this->entries;
    }

    public function getJiraId(): ?string
    {
        return $this->jiraId;
    }

    public function setJiraId(?string $jiraId): static
    {
        $this->jiraId = $jiraId;

        return $this;
    }

    public function getJiraTicket(): ?string
    {
        return $this->jiraTicket;
    }

    public function setJiraTicket(string $jiraTicket): static
    {
        if ('' === $jiraTicket) {
            $jiraTicket = null;
        }

        $this->jiraTicket = $jiraTicket;

        return $this;
    }

    /**
     * @return string[]
     *
     * @psalm-return list<string>
     */
    public function getSubtickets(): array
    {
        if ('' == $this->subtickets) {
            return [];
        }

        return explode(',', (string) $this->subtickets);
    }

    /**
     * @param (mixed|string)[] $subtickets
     *
     * @psalm-param array<mixed|string> $subtickets
     */
    public function setSubtickets(array $subtickets): static
    {
        $subtickets = implode(',', $subtickets);

        $this->subtickets = $subtickets;

        return $this;
    }

    /**
     * @return TicketSystem|null $ticketSystem
     */
    public function getTicketSystem(): ?TicketSystem
    {
        return $this->ticketSystem;
    }

    /**
     * Set the id of the ticket system that is associated with this project.
     *
     * @param TicketSystem $ticketSystem
     */
    public function setTicketSystem($ticketSystem): static
    {
        $this->ticketSystem = $ticketSystem;

        return $this;
    }

    public function getEstimation(): ?int
    {
        return $this->estimation;
    }

    public function setEstimation(?int $estimation): static
    {
        $this->estimation = $estimation;

        return $this;
    }

    public function getOffer(): ?string
    {
        return $this->offer;
    }

    public function setOffer(?string $offer): static
    {
        $this->offer = $offer;

        return $this;
    }

    public function getBilling(): ?int
    {
        return $this->billing;
    }

    public function setBilling(?int $billing): static
    {
        $this->billing = $billing;

        return $this;
    }

    public function getCostCenter(): ?string
    {
        return $this->costCenter;
    }

    public function setCostCenter(?string $costCenter): static
    {
        $this->costCenter = $costCenter;

        return $this;
    }

    public function getInvoice(): ?string
    {
        return $this->invoice;
    }

    public function setInvoice(?string $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    public function setProjectLead(?User $projectLead): static
    {
        $this->projectLead = $projectLead;

        return $this;
    }

    public function getProjectLead(): ?User
    {
        return $this->projectLead;
    }

    public function setTechnicalLead(?User $technicalLead): static
    {
        $this->technicalLead = $technicalLead;

        return $this;
    }

    public function getTechnicalLead(): ?User
    {
        return $this->technicalLead;
    }

    /**
     * Set internalReference.
     *
     * @param string $internalReference
     */
    public function setInternalReference($internalReference): static
    {
        $this->internalReference = $internalReference;

        return $this;
    }

    /**
     * Get internalReference.
     */
    public function getInternalReference(): ?string
    {
        return $this->internalReference;
    }

    /**
     * Set externalReference.
     *
     * @param string $externalReference
     */
    public function setExternalReference($externalReference): static
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    /**
     * Get externalReference.
     */
    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    /**
     * Add entry.
     */
    public function addEntry(Entry $entry): static
    {
        $this->entries[] = $entry;

        return $this;
    }

    /**
     * Remove entry.
     */
    public function removeEntry(Entry $entry): void
    {
        $this->entries->removeElement($entry);
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
     */
    public function hasInternalJiraProjectKey(): bool
    {
        return !empty($this->internalJiraProjectKey);
    }

    /**
     * Returns the id of the internal JIRA ticket system.
     */
    public function getInternalJiraTicketSystem(): ?string
    {
        $value = $this->internalJiraTicketSystem;
        if (null === $value || '' === $value) {
            return null;
        }
        return (string) $value;
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
     * Get presets.
     *
     * @psalm-return \Doctrine\Common\Collections\Collection<int, Preset>
     */
    public function getPresets(): \Doctrine\Common\Collections\Collection
    {
        return $this->presets;
    }

    /**
     * Add preset.
     */
    public function addPreset(Preset $preset): static
    {
        $this->presets[] = $preset;

        return $this;
    }

    /**
     * Remove preset.
     */
    public function removePreset(Preset $preset): void
    {
        $this->presets->removeElement($preset);
    }
}
