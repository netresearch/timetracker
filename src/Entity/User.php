<?php

namespace App\Entity;

use App\Helper\LocalizationHelper;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 *
 * @ORM\Entity(repositoryClass="App\Repository\UserRepository")
 * @ORM\Table(name="users")
 */
class User
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
     * @ORM\Column(type="string")
     */
    protected $abbr;

    /**
     * @ORM\Column(type="string")
     */
    protected $type;

    /**
     * @ORM\Column(name="show_empty_line", type="integer", nullable=false)
     */
    protected $showEmptyLine;

    /**
     * @ORM\Column(name="suggest_time", type="integer", nullable=false)
     */
    protected $suggestTime;


    /**
     * @ORM\Column(name="show_future", type="integer", nullable=false)
     */
    protected $showFuture;


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
     * @ORM\Column(name="locale", type="string", nullable=false)
     */
    protected $locale;


    /**
     * @ORM\OneToMany(targetEntity="UserTicketsystem", mappedBy="user")
     */
    protected $userTicketsystems;



    public function __construct()
    {
        $this->entries = new ArrayCollection();
    }

    /**
     * Set id
     * @param integer $id
     *
     * @return $this
     */
    public function setId($id)
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
    public function setUsername($username)
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
    public function setAbbr($abbr)
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
    public function setType($type)
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
     * Add entries
     *
     * @param Entry $entries
     *
     * @return $this
     */
    public function addEntries(Entry $entries)
    {
        $this->entries[] = $entries;
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
     * @param Contract $contract
     *
     * @return $this
     */
    public function addContract(Contract $contract)
    {
        $this->contracts[] = $contract;
        return $this;
    }


    /**
     * Reset teams
     *
     * @return $this
     */
    public function resetTeams()
    {
        $this->teams = new ArrayCollection();
        return $this;
    }

    /**
     * Add team
     *
     * @param Team $team
     *
     * @return $this
     */
    public function addTeam(Team $team)
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


    public function setLocale($locale)
    {
        $this->locale = LocalizationHelper::normalizeLocale($locale);
        return $this;
    }

    /**
     * return all relevant settings in an array
     */
    public function getSettings()
    {
        return array(
            'show_empty_line'   => $this->getShowEmptyLine(),
            'suggest_time'      => $this->getSuggestTime(),
            'show_future'       => $this->getShowFuture(),
            'user_id'           => $this->getId(),
            'user_name'         => $this->getUsername(),
            'type'              => $this->getType(),
            'locale'            => LocalizationHelper::normalizeLocale($this->getLocale())
        );
    }




    /**
     * Add entry
     *
     * @param Entry $entries
     * @return $this
     */
    public function addEntry(Entry $entries)
    {
        $this->entries[] = $entries;
        return $this;
    }

    /**
     * Remove entries
     *
     * @param Entry $entries
     */
    public function removeEntrie(Entry $entries)
    {
        $this->entries->removeElement($entries);
    }

    /**
     * Remove teams
     *
     * @param Team $teams
     */
    public function removeTeam(Team $teams)
    {
        $this->teams->removeElement($teams);
    }

    /**
     * Add entries
     *
     * @param Entry $entries
     * @return $this
     */
    public function addEntrie(Entry $entries)
    {
        $this->entries[] = $entries;

        return $this;
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
     * @param TicketSystem $ticketsystem
     * @return null|string
     */
    public function getTicketSystemAccessToken(TicketSystem $ticketsystem)
    {
        $return = null;
        /** @var $userTicketsystem UserTicketsystem */
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
     * @param TicketSystem $ticketsystem
     * @return null|string
     */
    public function getTicketSystemAccessTokenSecret(TicketSystem $ticketsystem)
    {
        $return = null;
        /** @var $userTicketsystem UserTicketsystem */
        foreach ($this->userTicketsystems as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem()->getId() == $ticketsystem->getId()) {
                $return = $userTicketsystem->getTokenSecret();
            }
        }
        return $return;
    }
}
