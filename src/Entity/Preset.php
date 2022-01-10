<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\PresetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Model\Base as Base;

#[ORM\Entity(repositoryClass: PresetRepository::class)]
#[ORM\Table(name: 'presets')]
class Preset extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: Types::STRING)]
    protected $name;

    #[ORM\ManyToOne(targetEntity: 'Project')]
    protected $project;

    #[ORM\ManyToOne(targetEntity: 'Customer')]
    protected $customer;

    #[ORM\ManyToOne(targetEntity: 'Activity')]
    protected $activity;

    #[ORM\Column(type: Types::STRING)]
    protected $description;

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
    public function getId(): int
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
    public function setName(string $name)
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
     *
     * @return int $customerId
     */
    public function getCustomerId(): int
    {
        return $this->getCustomer() ? $this->getCustomer()->getId() : 0;
    }

    /**
     * Get projectId.
     *
     * @return int $projectId
     */
    public function getProjectId(): int
    {
        return $this->getProject() ? $this->getProject()->getId() : 0;
    }

    /**
     * Get activityId.
     *
     * @return int $activityId
     */
    public function getActivityId(): int
    {
        return $this->getActivity() ? $this->getActivity()->getId() : 0;
    }

    /**
     * Set description.
     *
     * @param string $description
     *
     * @return $this
     */
    public function setDescription(string $description)
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
    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    /**
     * Set project.
     *
     * @return $this
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
    public function getProject(): Project
    {
        return $this->project;
    }

    /**
     * Set activity.
     *
     * @return $this
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
    public function getActivity(): Activity
    {
        return $this->activity;
    }

    /**
     * Get array representation of a preset object.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->getId(),
            'name'        => $this->getName(),
            'customer'    => $this->getCustomer() ? $this->getCustomer()->getId() : null,
            'project'     => $this->getProject() ? $this->getProject()->getId() : null,
            'activity'    => $this->getActivity() ? $this->getActivity()->getId() : null,
            'description' => $this->getDescription(),
        ];
    }
}
