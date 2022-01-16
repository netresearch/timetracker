<?php declare(strict_types=1);

namespace App\Entity;

use App\Entity\User\Types as UserTypes;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Common\Collections\Collection;
use App\Helper\LocalizationHelper;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue]
    private $id;

    #[ORM\Column(type: Types::STRING, length: 180, unique: true)]
    private $username;

    #[ORM\Column(type: Types::STRING)]
    protected $abbr = '';

    #[ORM\Column(type: Types::STRING, options: ["default" => UserTypes::DEV])]
    protected $type = UserTypes::DEV;

    #[ORM\Column(name: 'show_empty_line', type: Types::BOOLEAN)]
    protected $showEmptyLine = true;

    #[ORM\Column(name: 'suggest_time', type: Types::BOOLEAN)]
    protected $suggestTime = true;

    #[ORM\Column(name: 'show_future', type: Types::BOOLEAN)]
    protected $showFuture = true;

    #[ORM\OneToMany(targetEntity: 'Entry', mappedBy: 'user')]
    protected $entries;

    #[ORM\OneToMany(targetEntity: 'Contract', mappedBy: 'user')]
    protected $contracts;

    #[ORM\ManyToMany(targetEntity: 'Team', inversedBy: 'users')]
    #[ORM\JoinTable(name: 'teams_users')]
    protected $teams;

    #[ORM\Column(name: 'locale', type: Types::STRING, options: ["default" => 'en'])]
    protected $locale = 'en';

    #[ORM\OneToMany(targetEntity: 'UserTicketsystem', mappedBy: 'user')]
    protected $userTicketsystems;

    #[ORM\Column(type: 'json', options: ["default" => ''])]
    private $roles = [];

    #[ORM\Column(type: 'string', nullable: true)]
    private $password;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_DEV
        $roles[] = 'ROLE_DEV';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials()
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getUsername(): ?string
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

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getShowEmptyLine()
    {
        return $this->showEmptyLine;
    }

    public function setShowEmptyLine($value): static
    {
        $this->showEmptyLine = $value;

        return $this;
    }

    public function getSuggestTime()
    {
        return $this->suggestTime;
    }

    public function setSuggestTime($value): static
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

    public function addEntries(Entry $entries): static
    {
        $this->entries[] = $entries;

        return $this;
    }

    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function getContracts(): Collection
    {
        return $this->contracts;
    }

    public function addContract(Contract $contract): static
    {
        $this->contracts[] = $contract;

        return $this;
    }

    public function resetTeams(): static
    {
        $this->teams = new ArrayCollection();

        return $this;
    }

    public function addTeam(Team $team): static
    {
        $this->teams[] = $team;

        return $this;
    }

    public function getTeams(): Collection
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
     * return all relevant settings in an array.
     */
    public function getSettings(): array
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

    public function addEntry(Entry $entries): static
    {
        $this->entries[] = $entries;

        return $this;
    }

    public function removeEntrie(Entry $entries): void
    {
        $this->entries->removeElement($entries);
    }

    public function removeTeam(Team $teams): void
    {
        $this->teams->removeElement($teams);
    }

    public function addEntrie(Entry $entries): static
    {
        $this->entries[] = $entries;

        return $this;
    }

    public function getUserTicketsystems(): Collection
    {
        return $this->userTicketsystems;
    }

    public function getTicketSystemAccessToken(TicketSystem $ticketsystem): ?string
    {
        $return = null;
        /** @var UserTicketsystem $userTicketsystem */
        foreach ($this->userTicketsystems as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem()->getId() === $ticketsystem->getId()) {
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
    public function getTicketSystemAccessTokenSecret(TicketSystem $ticketsystem): ?string
    {
        $return = null;
        /** @var UserTicketsystem $userTicketsystem */
        foreach ($this->userTicketsystems as $userTicketsystem) {
            if ($userTicketsystem->getTicketSystem()->getId() === $ticketsystem->getId()) {
                $return = $userTicketsystem->getTokenSecret();
            }
        }

        return $return;
    }

    /**
     * {@inheritdoc}
     */
    public function isEqualTo(UserInterface $user): bool
    {
        if (!$user instanceof self) {
            return false;
        }

        if ($this->getPassword() !== $user->getPassword()) {
            return false;
        }

        if ($this->getUserIdentifier() !== $user->getUserIdentifier()) {
            return false;
        }

        return true;
    }
}
