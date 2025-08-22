<?php

namespace App\Entity;

use App\Service\Util\LocalizationService;
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
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    public $entries;
    /**
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    public $leadTeams;
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
    protected $username;

    /**
     * @ORM\Column (type="string", length=3, nullable=true)
     *
     * @var null|string
     */
    protected $abbr;

    /**
     * @ORM\Column (type="string", length=255)
     *
     * @var null|string
     */
    protected $type;

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
     * @ORM\ManyToMany(targetEntity="Team", inversedBy="users")
     * @ORM\JoinTable(name="teams_users",
     *     joinColumns={@ORM\JoinColumn(name="user_id", referencedColumnName="id", onDelete="CASCADE")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="team_id", referencedColumnName="id", onDelete="CASCADE")}
     * )
     * @var \Doctrine\Common\Collections\Collection<int, Team>
     */
    protected $teams;

    /**
     * @ORM\Column (name="locale", type="string", length=2, nullable=false, options={"default"="de"})
     */
    protected string $locale = 'de';


    /**
     * @ORM\OneToMany(targetEntity="UserTicketsystem", mappedBy="user")
     * @var \Doctrine\Common\Collections\Collection<int, UserTicketsystem>
     */
    protected $userTicketsystems;

    /**
     * @ORM\OneToMany(targetEntity="Entry", mappedBy="user")
     * @var \Doctrine\Common\Collections\Collection<int, Entry>
     */
    protected $entriesRelation;



    public function __construct()
    {
        $this->entries = new ArrayCollection();
        $this->leadTeams = new ArrayCollection();
        $this->entriesRelation = new ArrayCollection();
        $this->userTicketsystems = new ArrayCollection();
    }

    /**
     * Get id
     *
     * @return int|null $id
     */
    public function getId(): int|null
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
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
     * @return null|string $username
     */
    public function getUsername(): string|null
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
     * @return null|string $abbr
     */
    public function getAbbr(): string|null
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
     * @return null|string $type
     */
    public function getType(): string|null
    {
        return $this->type;
    }

    public function getShowEmptyLine(): bool
    {
        return $this->showEmptyLine;
    }

    public function setShowEmptyLine(bool $showEmptyLine): static
    {
        $this->showEmptyLine = $showEmptyLine;
        return $this;
    }


    public function getSuggestTime(): bool
    {
        return $this->suggestTime;
    }

    public function setSuggestTime(bool $suggestTime): static
    {
        $this->suggestTime = $suggestTime;
        return $this;
    }


    public function getShowFuture(): bool
    {
        return $this->showFuture;
    }

    public function setShowFuture(bool $showFuture): static
    {
        $this->showFuture = $showFuture;
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
     * @ORM\OneToMany(targetEntity="Contract", mappedBy="user")
     * @var \Doctrine\Common\Collections\Collection<int, Contract>
     */
    protected $contracts;

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Contract>
     */
    public function getContracts()
    {
        return $this->contracts;
    }

    /**
     * Get teams
     *
     * @return \Doctrine\Common\Collections\Collection $teams
     */
    /**
     * @return \Doctrine\Common\Collections\Collection<int, Team>
     */
    public function getTeams()
    {
        return $this->teams;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Entry>
     */
    public function getEntries()
    {
        return $this->entriesRelation;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, UserTicketsystem>
     */
    public function getUserTicketsystems()
    {
        return $this->userTicketsystems;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }


    public function setLocale(string $locale): static
    {
        $this->locale = (new LocalizationService())->normalizeLocale($locale);
        return $this;
    }

    /**
     * return all relevant settings in an array
     *
     * @return (bool|int|string)[]
     *
     * @psalm-return array{show_empty_line: bool, suggest_time: bool, show_future: bool, user_id: int, user_name: string, type: string, locale: string}
     */
    /**
     * @return array{show_empty_line: bool, suggest_time: bool, show_future: bool, user_id: int, user_name: string, type: string, locale: string}
     */
    public function getSettings(): array
    {
        return [
            'show_empty_line'   => $this->getShowEmptyLine(),
            'suggest_time'      => $this->getSuggestTime(),
            'show_future'       => $this->getShowFuture(),
            'user_id'           => (int) ($this->getId() ?? 0),
            'user_name'         => (string) ($this->getUsername() ?? ''),
            'type'              => (string) ($this->getType() ?? ''),
            'locale'            => (new LocalizationService())->normalizeLocale($this->getLocale())
        ];
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

    /**
     * @return string[]
     *
     * @psalm-return array<0|1, 'ROLE_ADMIN'|'ROLE_USER'>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if ($this->type === 'ADMIN') {
            $roles[] = 'ROLE_ADMIN';
        }

        return array_unique($roles);
    }

    public function getUserIdentifier(): string
    {
        return $this->username ?? '';
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }
}
