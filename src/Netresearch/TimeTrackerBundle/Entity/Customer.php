<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Netresearch\TimeTrackerBundle\Model\Base as Base;

/**
 *
 * @ORM\Entity
 * @ORM\Table(name="customers")
 * @ORM\Entity(repositoryClass="Netresearch\TimeTrackerBundle\Entity\CustomerRepository")
 */
class Customer extends Base
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
     * @ORM\Column(type="boolean")
     */
    protected $active;

    /**
     * @ORM\Column(type="boolean")
     */
    protected $global;

    /**
     * @ORM\OneToMany(targetEntity="Project", mappedBy="customer")
     */
    protected $projects;

    /**
     * @ORM\OneToMany(targetEntity="Entry", mappedBy="customer")
     */
    protected $entries;

    /**
     * @ORM\ManyToMany(targetEntity="Team", inversedBy="customers")
     * @ORM\JoinTable(name="teams_customers")
     */
    protected $teams;

    public function __construct()
    {
        $this->projects = new ArrayCollection();
        $this->entries  = new ArrayCollection();
        $this->teams    = new ArrayCollection();
    }


    /**
     * Set id
     * @param integer $id
     *
     * @return $this
     */
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
     *
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
     * Set active
     *
     * @param boolean $active
     *
     * @return $this
     */
    public function setActive($active)
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
     * Set global
     *
     * @param boolean $global
     *
     * @return $this
     */
    public function setGlobal($global)
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
     * Add projects
     *
     * @param Project $projects
     *
     * @return $this
     */
    public function addProjects(Project $projects)
    {
        $this->projects[] = $projects;
        return $this;
    }

    /**
     * Get projects
     *
     * @return \Doctrine\Common\Collections\Collection $projects
     */
    public function getProjects()
    {
        return $this->projects;
    }

    /**
     * Add entries
     *
     * @param Entry $entries
     *
     * @return $this
     */
    public function addEntries(Entry $entries)
    {
        $this->entries[] = $entries;
        return $this;
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

    /**
     * Reset teams
     *
     * @return $this
     */
    public function resetTeams()
    {
        $this->teams = new ArrayCollection();
        return $this;
    }

    /**
     * Add team
     *
     * @param Team $team
     *
     * @return $this
     */
    public function addTeam(Team $team)
    {
        $this->teams[] = $team;
        return $this;
    }

    /**
     * Get teams
     *
     * @return \Doctrine\Common\Collections\Collection $teams
     */
    public function getTeams()
    {
        return $this->teams;
    }

    /**
     * Add projects
     *
     * @param Project $projects
     * @return Customer
     */
    public function addProject(Project $projects)
    {
        $this->projects[] = $projects;
        return $this;
    }

    /**
     * Remove projects
     *
     * @param Project $projects
     */
    public function removeProject(Project $projects)
    {
        $this->projects->removeElement($projects);
    }

    /**
     * Add entries
     *
     * @param Entry $entry
     * @return Customer
     */
    public function addEntry(Entry $entry)
    {
        $this->entries[] = $entry;
        return $this;
    }

    /**
     * Remove entry
     *
     * @param Entry $entry
     */
    public function removeEntrie(Entry $entry)
    {
        $this->entries->removeElement($entry);
    }

    /**
     * Remove teams
     *
     * @param Team $teams
     */
    public function removeTeam(Team $teams)
    {
        $this->teams->removeElement($teams);
    }

    /**
     * Add entries
     *
     * @param Entry $entries
     * @return Customer
     */
    public function addEntrie(Entry $entries)
    {
        $this->entries[] = $entries;

        return $this;
    }
}
