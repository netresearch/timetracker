<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 *
 * @ORM\Entity
 * @ORM\Table(name="accounts")
 */
class Account
{
    /**
     * @ORM\Id
     *
     * @ORM\Column (type="integer")
     *
     * @ORM\GeneratedValue (strategy="AUTO")
     *
     * @var int|null
     */
    protected $id;

    /**
     * @ORM\Column (type="string", length=50)
     *
     * @var null|string
     */
    protected $name;

    /**
     * @ORM\OneToMany(targetEntity="Entry", mappedBy="account")
     * @var \Doctrine\Common\Collections\Collection<int, Entry>
     */
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
