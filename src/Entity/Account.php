<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: Types::STRING, length: 50)]
    protected $name;

    #[ORM\OneToMany(targetEntity: 'Entry', mappedBy: 'account')]
    protected $entries;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
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
     * Set id.
     *
     * @param int $id
     *
     * @return $this
     */
    public function setId(int $id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Set name.
     *
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
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
     * Add entries.
     */
    public function addEntries(Entry $entries): void
    {
        $this->entries[] = $entries;
    }

    /**
     * Get entries.
     *
     * @return Collection $entries
     */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    /**
     * Add entry.
     *
     * @return Account
     */
    public function addEntry(Entry $entry): self
    {
        $this->entries[] = $entry;

        return $this;
    }

    /**
     * Remove entry.
     */
    public function removeEntrie(Entry $entry): void
    {
        $this->entries->removeElement($entry);
    }

    /**
     * Add entries.
     *
     * @return Account
     */
    public function addEntrie(Entry $entries): self
    {
        $this->entries[] = $entries;

        return $this;
    }
}
