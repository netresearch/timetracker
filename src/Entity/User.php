<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UserType;
use App\Repository\UserRepository;
use App\Service\Util\LocalizationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use SensitiveParameter;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function password_verify;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TotpTwoFactorInterface, BackupCodeInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

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

    /** Deactivated users cannot log in and aren't offered for new lead assignments. */
    #[ORM\Column(name: 'active', type: 'boolean', nullable: false, options: ['default' => 1])]
    protected bool $active = true;

    /** Minimum entry duration in minutes — a new entry's end pre-fills to start + this. */
    #[ORM\Column(name: 'min_entry_duration', type: 'integer', nullable: false, options: ['default' => 5])]
    protected int $minEntryDuration = 5;

    /**
     * Symfony `auto` password hash for a LOCAL account (ADR-018 D1).
     * NULL = LDAP account: the credential check is the LDAP bind. When set, the
     * account authenticates against this hash and LDAP is never consulted for it.
     * Never exposed in API responses/DTOs (same rule as TicketSystem secrets).
     */
    #[ORM\Column(name: 'password', type: 'string', length: 255, nullable: true)]
    protected ?string $password = null;

    /**
     * TOTP shared secret, stored ENCRYPTED at rest (AES-256-GCM via
     * TokenEncryptionService — ADR-018 D2). NULL = TOTP not enrolled. The
     * decrypted value is never persisted; it is placed on {@see $totpSecretPlain}
     * on load by UserTwoFactorSubscriber and set there again on enrolment.
     */
    #[ORM\Column(name: 'totp_secret', type: 'string', length: 255, nullable: true)]
    protected ?string $totpSecret = null;

    /**
     * Hashed one-time backup codes (recovery codes). Each entry is a password
     * hash of a plain code shown to the user exactly once; verification and
     * single-use invalidation compare via the hash. NULL/[] = none outstanding.
     *
     * @var list<string>|null
     */
    #[ORM\Column(name: 'backup_codes', type: 'json', nullable: true)]
    protected ?array $backupCodes = null;

    /**
     * Stable, non-enumerable WebAuthn user handle (ADR-018 D3) — the opaque id
     * passkeys are bound to. NULL until the user registers their first passkey;
     * a random UUID is then assigned (never the integer PK, which is guessable).
     */
    #[ORM\Column(name: 'webauthn_user_handle', type: 'string', length: 36, unique: true, nullable: true)]
    protected ?string $webauthnUserHandle = null;

    /**
     * The DECRYPTED TOTP secret — a transient, non-persisted field. Populated
     * from {@see $totpSecret} by UserTwoFactorSubscriber::postLoad and on
     * enrolment. scheb reads it via getTotpAuthenticationConfiguration().
     * Excluded from serialization (see __serialize) so the plaintext never
     * reaches the session store when the stateful firewall persists the user.
     */
    private ?string $totpSecretPlain = null;

    /**
     * Transient cache of the backup-code hash matched by the last isBackupCode()
     * call, so invalidateBackupCode() can strip it without a second (deliberately
     * expensive) password_verify(). Never persisted or serialized.
     */
    private ?string $matchedBackupCodeHash = null;

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
        $this->type = is_string($type) ? UserType::from($type) : $type;

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

    public function getActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getMinEntryDuration(): int
    {
        return $this->minEntryDuration;
    }

    public function setMinEntryDuration(int $minEntryDuration): static
    {
        // Clamp to a sane range; 0 disables the end pre-fill, default is 5.
        $this->minEntryDuration = max(0, min(1440, $minEntryDuration));

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
        $this->locale = new LocalizationService()->normalizeLocale($locale);

        return $this;
    }

    /**
     * return all relevant settings in an array.
     *
     * Returns user settings for API responses.
     * Note: the three grid-display toggles (show_empty_line, suggest_time,
     * show_future) are returned as integers (0/1) for the legacy frontend; the
     * remaining flags (active, totp_enabled, local_account) are native booleans.
     *
     * @return array{show_empty_line: int, suggest_time: int, show_future: int, active: bool, min_entry_duration: int, user_name: string, user_id: int, type: string, locale: string, roles: array<string>, totp_enabled: bool, local_account: bool}
     */
    public function getSettings(): array
    {
        return [
            'show_empty_line' => (int) $this->getShowEmptyLine(),
            'suggest_time' => (int) $this->getSuggestTime(),
            'show_future' => (int) $this->getShowFuture(),
            'active' => $this->getActive(),
            'min_entry_duration' => $this->getMinEntryDuration(),
            'user_name' => $this->getUsername() ?? '',
            'user_id' => $this->getId() ?? 0,
            'type' => $this->getType()->value,
            'locale' => new LocalizationService()->normalizeLocale($this->getLocale()),
            'roles' => $this->getRoles(),
            // Drives the SPA Settings "Security" section: whether 2FA is already on,
            // and whether this is a local account (LDAP users cannot change a local
            // password, so that control is hidden for them).
            'totp_enabled' => $this->isTotpAuthenticationEnabled(),
            'local_account' => $this->isLocalAccount(),
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
     * The password hash used both for credential verification (local accounts)
     * and as the remember-me signature property (`signature_properties: ['password']`).
     *
     * - Local account: the stored `auto` hash. It changes when the password is
     *   reset, which correctly invalidates any outstanding remember-me cookie.
     * - LDAP account (hash NULL): a stable, non-secret synthetic value derived
     *   from username+id. It keeps remember-me working exactly as before (a
     *   real NULL here would make the signature hasher base64_encode(null) and
     *   trip a PHP deprecation) and is never used for credential verification,
     *   because LDAP accounts are routed to the LDAP bind, not the hasher.
     */
    public function getPassword(): ?string
    {
        return $this->password ?? hash('sha256', $this->username . '_ldap_user_' . ($this->id ?? '0'));
    }

    /**
     * Whether this is a local (password) account. When true, the login
     * authenticator verifies the password hash and never consults LDAP.
     */
    public function isLocalAccount(): bool
    {
        return null !== $this->password && '' !== $this->password;
    }

    /** The WebAuthn user handle, or null until the first passkey is registered. */
    public function getWebauthnUserHandle(): ?string
    {
        return $this->webauthnUserHandle;
    }

    public function setWebauthnUserHandle(?string $webauthnUserHandle): self
    {
        $this->webauthnUserHandle = $webauthnUserHandle;

        return $this;
    }

    /**
     * Sets the (already hashed) local password, or clears it (NULL) to revert
     * the account to LDAP authentication. Hashing happens in the caller via
     * UserPasswordHasherInterface — never store a plain-text value here.
     */
    public function setPassword(#[SensitiveParameter] ?string $hashedPassword): self
    {
        $this->password = $hashedPassword;

        return $this;
    }

    // ==================== TOTP two-factor (ADR-018 D2) ====================

    /**
     * The stored, ENCRYPTED TOTP secret (ciphertext), or NULL. Encryption/
     * decryption is the caller's job (TokenEncryptionService); the transient
     * plaintext lives on {@see $totpSecretPlain}.
     */
    public function getTotpSecret(): ?string
    {
        return $this->totpSecret;
    }

    /** Store the ENCRYPTED secret and the matching decrypted value (enrolment). */
    public function setTotpSecret(#[SensitiveParameter] ?string $encryptedSecret, #[SensitiveParameter] ?string $plainSecret): self
    {
        $this->totpSecret = $encryptedSecret;
        $this->totpSecretPlain = $plainSecret;

        return $this;
    }

    /** Set only the decrypted secret (called by the Doctrine post-load subscriber). */
    public function setTotpSecretPlain(#[SensitiveParameter] ?string $plainSecret): void
    {
        $this->totpSecretPlain = $plainSecret;
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return null !== $this->totpSecretPlain && '' !== $this->totpSecretPlain;
    }

    public function getTotpAuthenticationUsername(): ?string
    {
        return $this->username;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if (null === $this->totpSecretPlain || '' === $this->totpSecretPlain) {
            return null;
        }

        // RFC 6238 defaults compatible with every common authenticator app.
        return new TotpConfiguration($this->totpSecretPlain, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    // ==================== Backup codes (ADR-018 D2) ====================

    /**
     * Replace the outstanding recovery codes with the given HASHED codes (each a
     * password hash of a plain code shown to the user once). Hashing is the
     * caller's job — never store a plain code here.
     *
     * @param list<string> $hashedCodes
     */
    public function setBackupCodes(array $hashedCodes): self
    {
        $this->backupCodes = [] === $hashedCodes ? null : array_values($hashedCodes);

        return $this;
    }

    /** @return list<string> the outstanding HASHED codes */
    public function getBackupCodes(): array
    {
        return $this->backupCodes ?? [];
    }

    public function isBackupCode(#[SensitiveParameter] string $code): bool
    {
        foreach ($this->backupCodes ?? [] as $hashed) {
            if (password_verify($code, $hashed)) {
                // Cache the match so invalidateBackupCode() can skip the second
                // (deliberately expensive) verify (a fast string compare instead).
                $this->matchedBackupCodeHash = $hashed;

                return true;
            }
        }

        return false;
    }

    public function invalidateBackupCode(#[SensitiveParameter] string $code): void
    {
        $remaining = [];
        $consumed = false;
        foreach ($this->backupCodes ?? [] as $hashed) {
            // Strip the FIRST matching code only, so duplicate hashes (astronomically
            // unlikely) don't all vanish at once. Prefer the hash cached by a prior
            // isBackupCode() over a fresh password_verify().
            if (!$consumed && ($hashed === $this->matchedBackupCodeHash || password_verify($code, $hashed))) {
                $consumed = true;
                $this->matchedBackupCodeHash = null;

                continue;
            }
            $remaining[] = $hashed;
        }
        $this->backupCodes = [] === $remaining ? null : $remaining;
    }

    /**
     * Keep the transient plaintext fields OUT of the serialized form the stateful
     * firewall stores in the session: the decrypted TOTP secret and the cached
     * backup-code hash must never be persisted beyond the request (ADR-018 D2).
     * Everything else (incl. the ENCRYPTED totp_secret and HASHED backup codes)
     * serializes as before; the plain secret is re-derived on load by
     * UserTwoFactorSubscriber when the provider refreshes the user.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        // get_object_vars() yields string keys, so the result is array<string, mixed>;
        // array_diff_key loses that precision for PHPStan but not at runtime.
        /* @phpstan-ignore return.type */
        return array_diff_key(
            get_object_vars($this),
            ['totpSecretPlain' => null, 'matchedBackupCodeHash' => null],
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {
            // Restore the serialized property set; the two transient plaintext
            // fields were excluded above and keep their null default.
            /* @phpstan-ignore property.dynamicName */
            $this->{$property} = $value;
        }
    }
}
