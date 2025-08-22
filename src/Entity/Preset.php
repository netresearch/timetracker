<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Model\Base;

/**
 *
 * @ORM\Entity(repositoryClass="App\Repository\PresetRepository")
 * @ORM\Table(name="presets")
 */
class Preset extends Base
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     *
     * @var int|null
     */
    protected $id;


    /**
     * @ORM\Column (type="string")
     *
     * @var string
     */
    protected $name;

    /**
     * @ORM\ManyToOne (targetEntity="Project", inversedBy="presets")
     *
     * @ORM\JoinColumn (name="project_id", referencedColumnName="id")
     */
    protected Project $project;

    /**
     * @ORM\ManyToOne (targetEntity="Customer", inversedBy="presets")
     *
     * @ORM\JoinColumn (name="customer_id", referencedColumnName="id")
     */
    protected Customer $customer;

    /**
     * @ORM\ManyToOne (targetEntity="Activity", inversedBy="presets")
     *
     * @ORM\JoinColumn (name="activity_id", referencedColumnName="id")
     */
    protected Activity $activity;


    /**
     * @ORM\Column (type="string")
     *
     * @var string
     */
    protected $description;



    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get id
     *
     * @return integer $id
     */
    public function getId(): ?int
    {
        return $this->id;
    }


    /**
     * Set name
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
     * Get name
     *
     * @return string $name
     */
    public function getName(): string
    {
        return $this->name;
    }


    /**
     * Get customerId
     */
    public function getCustomerId(): ?int
    {
        return $this->getCustomer()->getId();
    }


    /**
     * Get projectId
     */
    public function getProjectId(): ?int
    {
        return $this->getProject()->getId();
    }


    /**
     * Get activityId
     *
     * @return integer $activityId
     */
    public function getActivityId(): ?int
    {
        return $this->getActivity()->getId();
    }



    /**
     * Set description
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
     * Get description
     *
     * @return string $description
     */
    public function getDescription(): string
    {
        return $this->description;
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
    public function getCustomer(): \App\Entity\Customer
    {
        return $this->customer;
    }


    /**
     * Set project
     *
     *
     * @return $this
     */
    public function setProject(Project $project): static
    {
        $this->project = $project;
        return $this;
    }

    /**
     * Get project
     *
     * @return Project $project
     */
    public function getProject(): \App\Entity\Project
    {
        return $this->project;
    }


    /**
     * Set activity
     *
     *
     * @return $this
     */
    public function setActivity(Activity $activity): static
    {
        $this->activity = $activity;
        return $this;
    }

    /**
     * Get activity
     *
     * @return Activity $activity
     */
    public function getActivity(): \App\Entity\Activity
    {
        return $this->activity;
    }


    /**
     * Get array representation of a preset object
     *
     * @return (int|null|string)[]
     *
     * @psalm-return array{id: int, name: string, customer: int|null, project: int|null, activity: int|null, description: string}
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->getId() ?? 0,
            'name'        => $this->getName(),
            'customer'    => $this->getCustomer()->getId(),
            'project'     => $this->getProject()->getId(),
            'activity'    => $this->getActivity()->getId(),
            'description' => $this->getDescription(),
        ];
    }
}
