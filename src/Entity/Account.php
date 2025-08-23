<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
class Account
{
    /**
     *
     *
     *
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * @var null|string
     */
    #[ORM\Column(type: 'string', length: 50)]
    protected $name;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Entry>
     */
    #[ORM\OneToMany(targetEntity: \Entry::class, mappedBy: 'account')]
    protected $entries;

    public function getId(): int|null
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string|null
    {
        return $this->name;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Entry>
     */
    public function getEntries()
    {
        return $this->entries;
    }
}
