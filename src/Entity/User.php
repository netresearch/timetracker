<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Common\Collections\Collection;
use App\Helper\LocalizationHelper;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: Types::STRING, length: 50)]
    protected $username;

    #[ORM\Column(type: Types::STRING)]
    protected $abbr = '';

    #[ORM\Column(type: Types::STRING)]
    protected $type;

    #[ORM\Column(name: 'show_empty_line', type: Types::INTEGER)]
    protected $showEmptyLine;

    #[ORM\Column(name: 'suggest_time', type: Types::INTEGER)]
    protected $suggestTime;

    #[ORM\Column(name: 'show_future', type: Types::INTEGER)]
    protected $showFuture;

    #[ORM\OneToMany(targetEntity: 'Entry', mappedBy: 'user')]
    protected $entries;

    #[ORM\OneToMany(targetEntity: 'Contract', mappedBy: 'user')]
    protected $contracts;

    #[ORM\ManyToMany(targetEntity: 'Team', inversedBy: 'users')]
    #[ORM\JoinTable(name: 'teams_users')]
    protected $teams;

    #[ORM\Column(name: 'locale', type: Types::STRING)]
    protected $locale;

    #[ORM\OneToMany(targetEntity: 'UserTicketsystem', mappedBy: 'user')]
    protected $userTicketsystems;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
    }

    /**
     * Set id.
     *
     * @param int $id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id.
     *
     * @return int $id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set username.
     *
     * @param string $username
     *
     * @return $this
     */
    public function setUsername($username)
    {
        $this->username = $username;

        return $this;
    }

    /**
     * Get username.
     *
     * @return string $username
     */
    public function getUsername()
    {
        return $this->username;
    }

    public function setAbbr(string $abbr): static
    {
        $this->abbr = $abbr;

        return $this;
    }

    public function getAbbr(): string
    {
        return $this->abbr;
    }

    /**
     * Set type.
     *
     * @param string $type
     *
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get type.
     *
     * @return string $type
     */
    public function getType()
    {
        return $this->type;
    }

    public function getShowEmptyLine()
    {
        return $this->showEmptyLine;
    }

    public function setShowEmptyLine($value)
    {
        $this->showEmptyLine = $value;

        return $this;
    }

    public function getSuggestTime()
    {
        return $this->suggestTime;
    }

    public function setSuggestTime($value)
    {
        $this->suggestTime = $value;

        return $this;
    }

    public function getShowFuture()
    {
        return $this->showFuture;
    }

    public function setShowFuture($value)
    {
        $this->showFuture = $value;

        return $this;
    }

    /**
     * Add entries.
     *
     * @return $this
     */
    public function addEntries(Entry $entries)
    {
        $this->entries[] = $entries;

        return $this;
    }

    /**
     * Get entries.
     *
     * @return Collection $entries
     */
    public function getEntries()
    {
        return $this->entries;
    }

    /**
     * Get contracts.
     *
     * @return Collection $contracts
     */
    public function getContracts()
    {
        return $this->contracts;
    }

    /**
     * Add contract.
     *
     * @return $this
     */
    public function addContract(Contract $contract)
    {
        $this->contracts[] = $contract;

        return $this;
    }

    /**
     * Reset teams.
     *
     * @return $this
     */
    public function resetTeams()
    {
        $this->teams = new ArrayCollection();

        return $this;
    }

    /**
     * Add team.
     *
     * @return $this
     */
    public function addTeam(Team $team)
    {
        $this->teams[] = $team;

        return $this;
    }

    /**
     * Get teams.
     *
     * @return Collection $teams
     */
    public function getTeams()
    {
        return $this->teams;
    }

    public function getLocale()
    {
        return $this->locale;
    }

    public function setLocale($locale)
    {
        $this->locale = LocalizationHelper::normalizeLocale($locale);

        return $this;
    }

    /**
     * return all relevant settings in an array.
     */
    public function getSettings()
    {
        return [
            'show_empty_line' => $this->getShowEmptyLine(),
            'suggest_time'    => $this->getSuggestTime(),
            'show_future'     => $this->getShowFuture(),
            'user_id'         => $this->getId(),
            'user_name'       => $this->getUsername(),
            'type'            => $this->getType(),
            'locale'          => LocalizationHelper::normalizeLocale($this->getLocale()),
        ];
    }

    /**
     * Add entry.
     *
     * @return $this
     */
    public function addEntry(Entry $entries)
    {
        $this->entries[] = $entries;

        return $this;
    }

    /**
     * Remove entries.
     */
    public function removeEntrie(Entry $entries)
    {
        $this->entries->removeElement($entries);
    }

    /**
     * Remove teams.
     */
    public function removeTeam(Team $teams)
    {
        $this->teams->removeElement($teams);
    }

    /**
     * Add entries.
     *
     * @return $this
     */
    public function addEntrie(Entry $entries)
    {
        $this->entries[] = $entries;

        return $this;
    }

    /**
     * @return Collection $userTicketSystems
     */
    public function getUserTicketsystems()
    {
        return $this->userTicketsystems;
    }

    /**
     * Get Users accesstoken for a Ticketsystem.
     *
     * @return string|null
     */
    public function getTicketSystemAccessToken(TicketSystem $ticketsystem)
    {
        $return = null;
        /** @var UserTicketsystem $userTicketsystem */
        foreach ($this->userTicketsystems as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem()->getId() == $ticketsystem->getId()) {
                $return = $userTicketsystem->getAccessToken();
            }
        }

        return $return;
    }

    /**
     * Get Users tokensecret for a Ticketsystem.
     *
     * @return string|null
     */
    public function getTicketSystemAccessTokenSecret(TicketSystem $ticketsystem)
    {
        $return = null;
        /** @var UserTicketsystem $userTicketsystem */
        foreach ($this->userTicketsystems as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem()->getId() == $ticketsystem->getId()) {
                $return = $userTicketsystem->getTokenSecret();
            }
        }

        return $return;
    }
}
