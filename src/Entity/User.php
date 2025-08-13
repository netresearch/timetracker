<?php

namespace App\Entity;

use App\Helper\LocalizationHelper;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 *
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
 * @ORM\Table(name="users")
 */
class User implements UserInterface
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
    protected $username;

    /**
     * @ORM\Column(type="string", length=3, nullable=true)
     */
    protected $abbr;

    /**
     * @ORM\Column(type="string", length=255)
     */
    protected $type;

    /**
     * @ORM\Column(name="jira_token", type="string", length=64, nullable=true)
     */
    protected $jiraToken;

    /**
     * @ORM\Column(name="show_empty_line", type="boolean", nullable=false, options={"default"=0})
     */
    protected bool $showEmptyLine = false;

    /**
     * @ORM\Column(name="suggest_time", type="boolean", nullable=false, options={"default"=1})
     */
    protected bool $suggestTime = true;


    /**
     * @ORM\Column(name="show_future", type="boolean", nullable=false, options={"default"=1})
     */
    protected bool $showFuture = true;


    /**
     * @ORM\OneToMany(targetEntity="Entry", mappedBy="user")
     */
    protected $entries;


    /**
     * @ORM\OneToMany(targetEntity="Contract", mappedBy="user")
     */
    protected $contracts;

    /**
     * @ORM\ManyToMany(targetEntity="Team", inversedBy="users")
     * @ORM\JoinTable(name="teams_users",
     *     joinColumns={@ORM\JoinColumn(name="user_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="team_id", referencedColumnName="id")}
     * )
     */
    protected $teams;

    /**
     * @ORM\OneToMany(targetEntity="Team", mappedBy="leadUser")
     */
    protected $leadTeams;

    /**
     * @ORM\Column(name="locale", type="string", length=2, nullable=false, options={"default"="de"})
     */
    protected $locale = 'de';


    /**
     * @ORM\OneToMany(targetEntity="UserTicketsystem", mappedBy="user")
     */
    protected $userTicketsystems;



    public function __construct()
    {
        $this->entries = new ArrayCollection();
        $this->leadTeams = new ArrayCollection();
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
     * Set username
     *
     * @param string $username
     *
     * @return $this
     */
    public function setUsername($username): static
    {
        $this->username = $username;
        return $this;
    }

    /**
     * Get username
     *
     * @return string $username
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Set abbr
     *
     * @param string $abbr
     *
     * @return $this
     */
    public function setAbbr($abbr): static
    {
        $this->abbr = $abbr;
        return $this;
    }

    /**
     * Get abbr
     *
     * @return string $abbr
     */
    public function getAbbr()
    {
        return $this->abbr;
    }

    /**
     * Set type
     *
     * @param string $type
     *
     * @return $this
     */
    public function setType($type): static
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Get type
     *
     * @return string $type
     */
    public function getType()
    {
        return $this->type;
    }

    public function getShowEmptyLine(): bool
    {
        return $this->showEmptyLine;
    }


    public function setShowEmptyLine(bool $value): static
    {
        $this->showEmptyLine = $value;
        return $this;
    }

    public function getSuggestTime(): bool
    {
        return $this->suggestTime;
    }


    public function setSuggestTime(bool $value): static
    {
        $this->suggestTime = $value;
        return $this;
    }

    public function getShowFuture(): bool
    {
        return $this->showFuture;
    }


    public function setShowFuture(bool $value): static
    {
        $this->showFuture = $value;
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
     * Get contracts
     *
     * @return \Doctrine\Common\Collections\Collection $contracts
     */
    public function getContracts()
    {
        return $this->contracts;
    }

    /**
     * Add contract
     *
     *
     * @return $this
     */
    public function addContract(Contract $contract): static
    {
        $this->contracts[] = $contract;
        return $this;
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

    public function getLocale()
    {
        return $this->locale;
    }


    public function setLocale($locale): static
    {
        $this->locale = LocalizationHelper::normalizeLocale($locale);
        return $this;
    }

    /**
     * return all relevant settings in an array
     */
    public function getSettings(): array
    {
        return [
            'show_empty_line'   => $this->getShowEmptyLine(),
            'suggest_time'      => $this->getSuggestTime(),
            'show_future'       => $this->getShowFuture(),
            'user_id'           => $this->getId(),
            'user_name'         => $this->getUsername(),
            'type'              => $this->getType(),
            'locale'            => LocalizationHelper::normalizeLocale($this->getLocale())
        ];
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
     * @return \Doctrine\Common\Collections\Collection $userTicketSystems
     */
    public function getUserTicketsystems()
    {
        return $this->userTicketsystems;
    }


    /**
     * Get Users accesstoken for a Ticketsystem
     *
     * @return null|string
     */
    public function getTicketSystemAccessToken(TicketSystem $ticketsystem)
    {
        $return = null;
        /** @var \App\Entity\UserTicketsystem $userTicketsystem */
        foreach ($this->userTicketsystems as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem()->getId() == $ticketsystem->getId()) {
                $return = $userTicketsystem->getAccessToken();
            }
        }

        return $return;
    }


    /**
     * Get Users tokensecret for a Ticketsystem
     *
     * @return null|string
     */
    public function getTicketSystemAccessTokenSecret(TicketSystem $ticketsystem)
    {
        $return = null;
        /** @var \App\Entity\UserTicketsystem $userTicketsystem */
        foreach ($this->userTicketsystems as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem()->getId() == $ticketsystem->getId()) {
                $return = $userTicketsystem->getTokenSecret();
            }
        }

        return $return;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if ($this->type === 'ADMIN') {
            $roles[] = 'ROLE_ADMIN';
        }

        return array_unique($roles);
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    public function getSalt(): ?string
    {
        // Since we're using LDAP, we don't need a salt
        return null;
    }

    public function getPassword(): string
    {
        // Since we're using LDAP, we don't store passwords
        return '';
    }

    /**
     * Get the teams where this user is a lead
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getLeadTeams()
    {
        return $this->leadTeams;
    }

    /**
     * Add a team where this user is lead
     */
    public function addLeadTeam(Team $team): static
    {
        $this->leadTeams[] = $team;
        return $this;
    }

    /**
     * Remove a team where this user is lead
     */
    public function removeLeadTeam(Team $team): void
    {
        $this->leadTeams->removeElement($team);
    }
}
