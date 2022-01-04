<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Model\Base as Base;

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
     */
    protected $id;


    /**
     * @ORM\Column(type="string")
     */
    protected $name;

    /**
     * @ORM\ManyToOne(targetEntity="Project", inversedBy="presets")
     * @ORM\JoinColumn(name="project_id", referencedColumnName="id")
     */
    protected $project;

    /**
     * @ORM\ManyToOne(targetEntity="Customer", inversedBy="presets")
     * @ORM\JoinColumn(name="customer_id", referencedColumnName="id")
     */
    protected $customer;

     /**
     * @ORM\ManyToOne(targetEntity="Activity", inversedBy="presets")
     * @ORM\JoinColumn(name="activity_id", referencedColumnName="id")
     */
    protected $activity;


    /**
     * @ORM\Column(type="string")
     */
    protected $description;



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
     * Set name
     * *
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
     * Get name
     *
     * @return string $name
     */
    public function getName()
    {
        return $this->name;
    }


    /**
     * Get customerId
     *
     * @return integer $customerId
     */
    public function getCustomerId()
    {
        return $this->getCustomer() ? $this->getCustomer()->getId() : 0;
    }


    /**
     * Get projectId
     *
     * @return integer $projectId
     */
    public function getProjectId()
    {
        return $this->getProject() ? $this->getProject()->getId() : 0;
    }


    /**
     * Get activityId
     *
     * @return integer $activityId
     */
    public function getActivityId()
    {
        return $this->getActivity() ? $this->getActivity()->getId() : 0;
    }



    /**
     * Set description
     *
     * @param string $description
     *
     * @return $this
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
     * Set customer
     *
     *
     * @return $this
     */
    public function setCustomer(Customer $customer)
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
     * Set project
     *
     *
     * @return $this
     */
    public function setProject(Project $project)
    {
        $this->project = $project;
        return $this;
    }

    /**
     * Get project
     *
     * @return Project $project
     */
    public function getProject()
    {
        return $this->project;
    }


    /**
     * Set activity
     *
     *
     * @return $this
     */
    public function setActivity(Activity $activity)
    {
        $this->activity = $activity;
        return $this;
    }

    /**
     * Get activity
     *
     * @return Activity $activity
     */
    public function getActivity()
    {
        return $this->activity;
    }


    /**
     * Get array representation of a preset object
     *
     * @return array
     */
    public function toArray()
    {
        return array(
            'id'          => $this->getId(),
            'name'        => $this->getName(),
            'customer'    => $this->getCustomer() ? $this->getCustomer()->getId() : null,
            'project'     => $this->getProject() ? $this->getProject()->getId() : null,
            'activity'    => $this->getActivity() ? $this->getActivity()->getId() : null,
            'description' => $this->getDescription(),
        );
    }
}
