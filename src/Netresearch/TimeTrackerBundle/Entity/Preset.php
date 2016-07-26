<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Netresearch\TimeTrackerBundle\Model\Base as Base;

/**
 *
 * @ORM\Entity
 * @ORM\Table(name="presets")
 * @ORM\Entity(repositoryClass="Netresearch\TimeTrackerBundle\Entity\PresetRepository")
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
     * @param \Netresearch\TimeTrackerBundle\Entity\Customer $customer
     *
     * @return $this
     */
    public function setCustomer(\Netresearch\TimeTrackerBundle\Entity\Customer $customer)
    {
        $this->customer = $customer;
        return $this;
    }

    /**
     * Get customer
     *
     * @return \Netresearch\TimeTrackerBundle\Entity\Customer $customer
     */
    public function getCustomer()
    {
        return $this->customer;
    }


    /**
     * Set project
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Project $project
     *
     * @return $this
     */
    public function setProject(\Netresearch\TimeTrackerBundle\Entity\Project $project)
    {
        $this->project = $project;
        return $this;
    }

    /**
     * Get project
     *
     * @return \Netresearch\TimeTrackerBundle\Entity\Project $project
     */
    public function getProject()
    {
        return $this->project;
    }


    /**
     * Set activity
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Activity $activity
     *
     * @return $this
     */
    public function setActivity(\Netresearch\TimeTrackerBundle\Entity\Activity $activity)
    {
        $this->activity = $activity;
        return $this;
    }

    /**
     * Get activity
     *
     * @return \Netresearch\TimeTrackerBundle\Entity\Activity $activity
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
            'id'            => $this->getId(),
            'name'          => $this->getName(),
            'customer'      => $this->getCustomer() ? $this->getCustomer()->getId() : null,
            'project'       => $this->getProject() ? $this->getProject()->getId() : null,
            'activity'      => $this->getActivity() ? $this->getActivity()->getId() : null,
            'description'   => $this->getDescription()
        );
    }
}
