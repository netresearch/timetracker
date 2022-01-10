<?php declare(strict_types=1);

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

    #[ORM\Column(name: 'show_future', type: Types::BOOLEAN)]
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
    public function setId(int $id)
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    public function getUsername(): string
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
}
