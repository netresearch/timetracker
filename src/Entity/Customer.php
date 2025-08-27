<?php
declare(strict_types=1);

namespace App\Entity;

use App\Model\Base;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
class Customer extends Base
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
    #[ORM\Column(type: 'string', length: 255)]
    protected string $name = '';

    /**
     * @var bool
     */
    #[ORM\Column(type: 'boolean', options: ['unsigned' => true, 'default' => 0])]
    protected $active = false;

    /**
     * @var bool
     */
    #[ORM\Column(type: 'boolean', options: ['unsigned' => true, 'default' => 0])]
    protected $global = false;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Project>
     */
    #[ORM\OneToMany(targetEntity: Project::class, mappedBy: 'customer')]
    protected $projects;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Entry>
     */
    #[ORM\OneToMany(targetEntity: Entry::class, mappedBy: 'customer')]
    protected $entries;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Preset>
     */
    #[ORM\OneToMany(targetEntity: Preset::class, mappedBy: 'customer')]
    protected $presets;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Team>
     */
    #[ORM\ManyToMany(targetEntity: Team::class, inversedBy: 'customers')]
    #[ORM\JoinTable(name: 'teams_customers', joinColumns: [new ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id')], inverseJoinColumns: [new ORM\JoinColumn(name: 'team_id', referencedColumnName: 'id')])]
    protected $teams;

    public function __construct()
    {
        $this->projects = new ArrayCollection();
        $this->entries = new ArrayCollection();
        $this->teams = new ArrayCollection();
        $this->presets = new ArrayCollection();
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
     * Get id.
     *
     * @return int|null $id
     */
    public function getId(): ?int
    {
        return $this->id;
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
     * @return bool $active
     */
    public function getActive()
    {
        return $this->active;
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
        $this->global = $global;

        return $this;
    }

    /**
     * Get global.
     *
     * @return bool $global
     */
    public function getGlobal()
    {
        return $this->global;
    }

    /**
     * Add projects.
     *
     * @return $this
     */
    public function addProjects(Project $project): static
    {
        $this->projects[] = $project;

        return $this;
    }

    /**
     * Get projects.
     *
     * @return \Doctrine\Common\Collections\Collection<int, Project>
     */
    public function getProjects()
    {
        return $this->projects;
    }

    /**
     * Get entries.
     *
     * @return \Doctrine\Common\Collections\Collection<int, Entry>
     */
    public function getEntries()
    {
        return $this->entries;
    }

    /**
     * Reset teams.
     *
     * @return $this
     */
    public function resetTeams(): static
    {
        $this->teams = new ArrayCollection();

        return $this;
    }

    /**
     * Add team.
     *
     * @return $this
     */
    public function addTeam(Team $team): static
    {
        $this->teams[] = $team;

        return $this;
    }

    /**
     * Get teams.
     *
     * @return \Doctrine\Common\Collections\Collection<int, Team>
     */
    public function getTeams()
    {
        return $this->teams;
    }

    /**
     * Add projects.
     */
    public function addProject(Project $project): static
    {
        $this->projects[] = $project;

        return $this;
    }

    /**
     * Remove projects.
     */
    public function removeProject(Project $project): void
    {
        $this->projects->removeElement($project);
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
     * Remove teams.
     */
    public function removeTeam(Team $team): void
    {
        $this->teams->removeElement($team);
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Preset>
     */
    public function getPresets()
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
