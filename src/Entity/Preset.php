<?php

namespace App\Entity;

use App\Model\Base;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\PresetRepository::class)]
#[ORM\Table(name: 'presets')]
class Preset extends Base
{
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
    #[ORM\Column(type: 'string')]
    protected $name;

    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'presets')]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: true)]
    protected ?Project $project = null;

    #[ORM\ManyToOne(targetEntity: Customer::class, inversedBy: 'presets')]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: true)]
    protected ?Customer $customer = null;

    #[ORM\ManyToOne(targetEntity: Activity::class, inversedBy: 'presets')]
    #[ORM\JoinColumn(name: 'activity_id', referencedColumnName: 'id', nullable: true)]
    protected ?Activity $activity = null;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string')]
    protected $description;

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id.
     *
     * @return int $id
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set name.
     * *
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
     * Get name.
     *
     * @return string $name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get customerId.
     */
    public function getCustomerId(): ?int
    {
        return $this->getCustomer() instanceof Customer ? $this->getCustomer()->getId() : null;
    }

    /**
     * Get projectId.
     */
    public function getProjectId(): ?int
    {
        return $this->getProject() instanceof Project ? $this->getProject()->getId() : null;
    }

    /**
     * Get activityId.
     *
     * @return int $activityId
     */
    public function getActivityId(): ?int
    {
        return $this->getActivity() instanceof Activity ? $this->getActivity()->getId() : null;
    }

    /**
     * Set description.
     *
     * @param string $description
     *
     * @return $this
     */
    public function setDescription($description): static
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
     * @return Customer $customer
     */
    public function getCustomer(): Customer
    {
        return $this->customer ?? new Customer();
    }

    /**
     * Set project.
     *
     * @return $this
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
    public function getProject(): Project
    {
        return $this->project ?? new Project();
    }

    /**
     * Set activity.
     *
     * @return $this
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
    public function getActivity(): Activity
    {
        return $this->activity ?? new Activity();
    }

    /**
     * Get array representation of a preset object.
     *
     * @return (int|string|null)[]
     *
     * @psalm-return array{id: int, name: string, customer: int|null, project: int|null, activity: int|null, description: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId() ?? 0,
            'name' => $this->getName(),
            'customer' => $this->getCustomer()->getId(),
            'project' => $this->getProject()->getId(),
            'activity' => $this->getActivity()->getId(),
            'description' => $this->getDescription(),
        ];
    }
}
