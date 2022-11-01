<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\CustomerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use App\Model\Base;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: CustomerRepository::class)]
#[ORM\Table(name: 'customers')]
class Customer extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    #[Groups('entry')]
    protected $id;

    #[ORM\Column(type: Types::STRING)]
    #[Groups('entry')]
    protected $name;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => 1])]
    protected $active = true;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => 0])]
    protected $global = false;

    #[ORM\OneToMany(targetEntity: 'Project', mappedBy: 'customer')]
    protected $projects;

    #[ORM\OneToMany(targetEntity: 'Entry', mappedBy: 'customer')]
    protected $entries;

    #[ORM\ManyToMany(targetEntity: 'Team', mappedBy: 'customers')]
    protected $teams;

    public function __construct()
    {
        $this->projects = new ArrayCollection();
        $this->entries  = new ArrayCollection();
        $this->teams    = new ArrayCollection();
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getActive(): bool
    {
        return $this->active;
    }

    public function setGlobal(bool $global): static
    {
        $this->global = $global;

        return $this;
    }

    public function getGlobal(): bool
    {
        return $this->global;
    }

    public function addProjects(Project $projects): static
    {
        $this->projects[] = $projects;

        return $this;
    }

    public function getProjects(): Collection
    {
        return $this->projects;
    }

    public function addEntries(Collection $entries): static
    {
        $this->entries = new ArrayCollection(
            array_merge($this->entries->toArray(), $entries->toArray())
        );

        return $this;
    }

    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function resetTeams(): static
    {
        $this->teams = new ArrayCollection();

        return $this;
    }

    public function addTeam(Team $team): static
    {
        $this->teams[] = $team;

        return $this;
    }

    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addProject(Project $projects): static
    {
        $this->projects[] = $projects;

        return $this;
    }

    public function removeProject(Project $projects): bool
    {
        return $this->projects->removeElement($projects);
    }

    public function addEntry(Entry $entry): static
    {
        $this->entries[] = $entry;

        return $this;
    }

    public function removeEntrie(Entry $entry): bool
    {
        return $this->entries->removeElement($entry);
    }

    public function removeTeam(Team $teams): bool
    {
        return $this->teams->removeElement($teams);
    }

    public function addEntrie(Entry $entries): static
    {
        $this->entries[] = $entries;

        return $this;
    }
}
