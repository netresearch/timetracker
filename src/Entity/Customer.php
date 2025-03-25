<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use App\Model\Base;

/**
 *
 * @ORM\Entity(repositoryClass="App\Repository\CustomerRepository")
 * @ORM\Table(name="customers")
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
     * @ORM\OneToMany(targetEntity="Preset", mappedBy="customer")
     */
    protected $presets;

    /**
     * @ORM\ManyToMany(targetEntity="Team", inversedBy="customers")
     * @ORM\JoinTable(name="teams_customers",
     *     joinColumns={@ORM\JoinColumn(name="customer_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="team_id", referencedColumnName="id")}
     * )
     */
    protected $teams;

    public function __construct()
    {
        $this->projects = new ArrayCollection();
        $this->entries  = new ArrayCollection();
        $this->teams    = new ArrayCollection();
        $this->presets  = new ArrayCollection();
    }


    /**
     * Set id
     * @param integer $id
     *
     * @return $this
     */
    public function setId($id): static
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
    public function setActive($active): static
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
    public function setGlobal($global): static
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
    public function addProjects(Project $project): static
    {
        $this->projects[] = $project;
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
    public function resetTeams(): static
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
    public function addTeam(Team $team): static
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
     */
    public function addProject(Project $project): static
    {
        $this->projects[] = $project;
        return $this;
    }

    /**
     * Remove projects
     */
    public function removeProject(Project $project): void
    {
        $this->projects->removeElement($project);
    }

    /**
     * Add entry
     */
    public function addEntry(Entry $entry): static
    {
        $this->entries[] = $entry;
        return $this;
    }

    /**
     * Remove entry
     */
    public function removeEntry(Entry $entry): void
    {
        $this->entries->removeElement($entry);
    }

    /**
     * Remove teams
     */
    public function removeTeam(Team $team): void
    {
        $this->teams->removeElement($team);
    }

    /**
     * Get presets
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getPresets()
    {
        return $this->presets;
    }

    /**
     * Add preset
     */
    public function addPreset(Preset $preset): static
    {
        $this->presets[] = $preset;
        return $this;
    }

    /**
     * Remove preset
     */
    public function removePreset(Preset $preset): void
    {
        $this->presets->removeElement($preset);
    }
}
