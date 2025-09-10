<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserType;
use App\Service\Util\LocalizationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;

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

    #[ORM\Column(type: 'string', length: 50)]
    protected string $username = '';

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 3, nullable: true)]
    protected $abbr;

    #[ORM\Column(type: 'string', length: 255, enumType: UserType::class)]
    protected UserType $type = UserType::USER;

    #[ORM\Column(name: 'jira_token', type: 'string', length: 255, nullable: true)]
    protected ?string $jiraToken = null;

    #[ORM\Column(name: 'show_empty_line', type: 'boolean', nullable: false, options: ['default' => 0])]
    protected bool $showEmptyLine = false;

    #[ORM\Column(name: 'suggest_time', type: 'boolean', nullable: false, options: ['default' => 1])]
    protected bool $suggestTime = true;

    #[ORM\Column(name: 'show_future', type: 'boolean', nullable: false, options: ['default' => 1])]
    protected bool $showFuture = true;

    /**
     * @var Collection<int, Team>
     */
    #[ORM\ManyToMany(targetEntity: Team::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'teams_users', joinColumns: [new ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')], inverseJoinColumns: [new ORM\JoinColumn(name: 'team_id', referencedColumnName: 'id', onDelete: 'CASCADE')])]
    protected Collection $teams;

    #[ORM\Column(name: 'locale', type: 'string', length: 2, nullable: false, options: ['default' => 'de'])]
    protected string $locale = 'de';

    /**
     * @var Collection<int, UserTicketsystem>
     */
    #[ORM\OneToMany(targetEntity: UserTicketsystem::class, mappedBy: 'user')]
    protected Collection $userTicketsystems;

    /**
     * @var Collection<int, Entry>
     */
    #[ORM\OneToMany(targetEntity: Entry::class, mappedBy: 'user')]
    protected Collection $entriesRelation;

    /**
     * @var Collection<int, Contract>
     */
    #[ORM\OneToMany(targetEntity: Contract::class, mappedBy: 'user')]
    protected Collection $contracts;

    public function __construct()
    {
        // Initialize all collections in constructor to fix PropertyNotSetInConstructor
        $this->teams = new ArrayCollection();
        $this->contracts = new ArrayCollection();
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
     * @return $this
     */
    public function setUsername(string $username): static
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
     * @return $this
     */
    public function setType(UserType|string $type): static
    {
        if (is_string($type)) {
            $this->type = UserType::from($type);
        } else {
            $this->type = $type;
        }

        return $this;
    }

    /**
     * Get type.
     *
     * @return UserType $type
     */
    public function getType(): UserType
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
     * Get teams.
     *
     * @return Collection<int, Team>
     */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    /**
     * @return Collection<int, UserTicketsystem>
     */
    public function getUserTicketsystems(): Collection
    {
        return $this->userTicketsystems;
    }

    /**
     * @return Collection<int, Entry>
     */
    public function getEntries(): Collection
    {
        return $this->entriesRelation;
    }

    /**
     * @return Collection<int, Contract>
     */
    public function getContracts(): Collection
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
            'type' => $this->getType()->value,
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
     * @return array<int<0, 2>, 'ROLE_ADMIN'|'ROLE_PL'|'ROLE_USER'>
     *
     * @psalm-return array<int<0, 2>, 'ROLE_ADMIN'|'ROLE_PL'|'ROLE_USER'>
     */
    public function getRoles(): array
    {
        $roles = $this->type->getRoles();

        // Convert to array with numeric keys as expected by Symfony
        /* @phpstan-ignore-next-line */
        return array_values($roles);
    }

    public function getUserIdentifier(): string
    {
        // Guarantee non-empty string as required by Symfony contracts
        return '' !== $this->username ? $this->username : '_';
    }

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    /**
     * Returns a password-like string for remember_me functionality.
     * Since LDAP users don't have stored passwords, we generate a stable hash
     * based on the username for security signature generation.
     */
    public function getPassword(): ?string
    {
        // Generate a stable hash for remember_me functionality
        // This is not the actual password but a consistent value for this user
        return hash('sha256', $this->username . '_ldap_user_' . ($this->id ?? '0'));
    }
}
