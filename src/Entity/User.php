<?php
declare(strict_types=1);

namespace App\Entity;

use App\Service\Util\LocalizationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: \App\Repository\UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface
{
    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 50)]
    protected string $username = '';

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    protected $abbr;

    /**
     * @var string
     */
    #[ORM\Column(type: 'string', length: 255)]
    protected string $type = '';

    #[ORM\Column(name: 'jira_token', type: 'string', length: 255, nullable: true)]
    protected ?string $jiraToken = null;

    #[ORM\Column(name: 'show_empty_line', type: 'boolean', nullable: false, options: ['default' => 0])]
    protected bool $showEmptyLine = false;

    #[ORM\Column(name: 'suggest_time', type: 'boolean', nullable: false, options: ['default' => 1])]
    protected bool $suggestTime = true;

    #[ORM\Column(name: 'show_future', type: 'boolean', nullable: false, options: ['default' => 1])]
    protected bool $showFuture = true;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Team>
     */
    #[ORM\ManyToMany(targetEntity: Team::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'teams_users', joinColumns: [new ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')], inverseJoinColumns: [new ORM\JoinColumn(name: 'team_id', referencedColumnName: 'id', onDelete: 'CASCADE')])]
    protected $teams;

    #[ORM\Column(name: 'locale', type: 'string', length: 2, nullable: false, options: ['default' => 'de'])]
    protected string $locale = 'de';

    /**
     * @var \Doctrine\Common\Collections\Collection<int, UserTicketsystem>
     */
    #[ORM\OneToMany(targetEntity: UserTicketsystem::class, mappedBy: 'user')]
    protected $userTicketsystems;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Entry>
     */
    #[ORM\OneToMany(targetEntity: Entry::class, mappedBy: 'user')]
    protected $entriesRelation;

    public function __construct()
    {
        $this->entriesRelation = new ArrayCollection();
        $this->userTicketsystems = new ArrayCollection();
    }

    /**
     * Get id.
     *
     * @return int|null $id
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Set username.
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
     * Get username.
     *
     * @return string|null $username
     */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /**
     * Set abbr.
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
     * Get abbr.
     *
     * @return string|null $abbr
     */
    public function getAbbr(): ?string
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
    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get type.
     *
     * @return string|null $type
     */
    public function getType(): ?string
    {
        return $this->type;
    }

    public function getJiraToken(): ?string
    {
        return $this->jiraToken;
    }

    public function setJiraToken(?string $jiraToken): static
    {
        $this->jiraToken = $jiraToken;

        return $this;
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
     * Reset teams.
     *
     * @return $this
     */
    public function resetTeams(): static
    {
        $this->teams = new ArrayCollection();

        return $this;
    }

    /**
     * Add team.
     *
     * @return $this
     */
    public function addTeam(Team $team): static
    {
        $this->teams[] = $team;

        return $this;
    }

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Contract>
     */
    #[ORM\OneToMany(targetEntity: Contract::class, mappedBy: 'user')]
    protected $contracts;

    /**
     * Get teams.
     *
     * @return \Doctrine\Common\Collections\Collection<int, Team>
     */
    public function getTeams()
    {
        return $this->teams;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, UserTicketsystem>
     */
    public function getUserTicketsystems()
    {
        return $this->userTicketsystems;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Entry>
     */
    public function getEntries()
    {
        return $this->entriesRelation;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Contract>
     */
    public function getContracts()
    {
        return $this->contracts;
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
     * return all relevant settings in an array.
     *
     * @return array{show_empty_line: bool, suggest_time: bool, show_future: bool, user_id: int, user_name: string, type: string, locale: string}
     */
    public function getSettings(): array
    {
        return [
            'show_empty_line' => $this->getShowEmptyLine(),
            'suggest_time' => $this->getSuggestTime(),
            'show_future' => $this->getShowFuture(),
            'user_id' => $this->getId() ?? 0,
            'user_name' => $this->getUsername() ?? '',
            'type' => $this->getType() ?? '',
            'locale' => (new LocalizationService())->normalizeLocale($this->getLocale()),
        ];
    }

    /**
     * Get Users accesstoken for a Ticketsystem.
     *
     * @return string|null
     */
    public function getTicketSystemAccessToken(TicketSystem $ticketsystem)
    {
        $return = null;
        foreach ($this->userTicketsystems as $userTicketsystem) {
            $ts = $userTicketsystem->getTicketSystem();
            if ($ts instanceof TicketSystem && $ts->getId() === $ticketsystem->getId()) {
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
        foreach ($this->userTicketsystems as $userTicketsystem) {
            $ts = $userTicketsystem->getTicketSystem();
            if ($ts instanceof TicketSystem && $ts->getId() === $ticketsystem->getId()) {
                $return = $userTicketsystem->getTokenSecret();
            }
        }

        return $return;
    }

    /**
     * @return string[]
     *
     * @psalm-return array<int<0, 1>, 'ROLE_ADMIN'|'ROLE_USER'>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if ('ADMIN' === $this->type) {
            $roles[] = 'ROLE_ADMIN';
        }

        return array_unique($roles);
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }
}
