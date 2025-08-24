<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'accounts')]
class Account
{
    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 50)]
    protected $name;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Entry>
     */
    #[ORM\OneToMany(targetEntity: \Entry::class, mappedBy: 'account')]
    protected $entries;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
