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
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=50)
     */
    protected $name;

    /**
     * @ORM\OneToMany(targetEntity="Entry", mappedBy="account")
     */
    protected $entries;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
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
     * Set id
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
     * Set name
     *
     * @param string $name
     */
    public function setName($name): void
    {
        $this->name = $name;
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
     * Add entries
     */
    public function addEntries(Entry $entry): void
    {
        $this->entries[] = $entry;
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
    public function removeEntrie(Entry $entry): void
    {
        $this->entries->removeElement($entry);
    }

    /**
     * Add entries
     */
    public function addEntrie(Entry $entry): static
    {
        $this->entries[] = $entry;

        return $this;
    }
}
