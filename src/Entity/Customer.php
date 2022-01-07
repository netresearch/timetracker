<?php

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use App\Model\Base;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
class Customer extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;
    #[ORM\Column(type: Types::STRING)]
    protected $name;
    #[ORM\Column(type: Types::BOOLEAN)]
    protected $active;
    #[ORM\Column(type: Types::BOOLEAN)]
    protected $global;
    #[ORM\OneToMany(targetEntity: 'Project', mappedBy: 'customer')]
    protected $projects;
    #[ORM\OneToMany(targetEntity: 'Entry', mappedBy: 'customer')]
    protected $entries;
    #[ORM\ManyToMany(targetEntity: 'Team', inversedBy: 'customers')]
    #[ORM\JoinTable(name: 'teams_customers', joinColumns: [new ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'team_id', referencedColumnName: 'id')])]
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
     * @return Collection $projects
     */
    public function getProjects()
    {
        return $this->projects;
    }
    /**
     * Add entries
     *
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
     * @return Collection $entries
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
     * @return Collection $teams
     */
    public function getTeams()
    {
        return $this->teams;
    }
    /**
     * Add projects
     *
     * @return Customer
     */
    public function addProject(Project $projects)
    {
        $this->projects[] = $projects;
        return $this;
    }
    /**
     * Remove projects
     */
    public function removeProject(Project $projects)
    {
        $this->projects->removeElement($projects);
    }
    /**
     * Add entries
     *
     * @return Customer
     */
    public function addEntry(Entry $entry)
    {
        $this->entries[] = $entry;
        return $this;
    }
    /**
     * Remove entry
     */
    public function removeEntrie(Entry $entry)
    {
        $this->entries->removeElement($entry);
    }
    /**
     * Remove teams
     */
    public function removeTeam(Team $teams)
    {
        $this->teams->removeElement($teams);
    }
    /**
     * Add entries
     *
     * @return Customer
     */
    public function addEntrie(Entry $entries)
    {
        $this->entries[] = $entries;

        return $this;
    }
}
