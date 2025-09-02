<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_key_exists;

class TokenStub implements TokenInterface
{
    private array $attributes = [];

    private bool $authenticated = true;

    public function __construct(private ?UserInterface $user = null)
    {
    }

    public function __toString(): string
    {
        return 'token-stub';
    }

    public function getRoles(): array
    {
        return [];
    }

    public function getRoleNames(): array
    {
        return [];
    }

    public function getCredentials(): mixed
    {
        return null;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(UserInterface|string $user): void
    {
        $this->user = $user instanceof UserInterface ? $user : null;
    }

    public function getUsername(): string
    {
        return method_exists($this->user, 'getUsername') ? $this->user->getUserIdentifier() : '';
    }

    public function getUserIdentifier(): string
    {
        if ($this->user instanceof UserInterface) {
            if (method_exists($this->user, 'getUserIdentifier')) {
                return $this->user->getUserIdentifier();
            }

            if (method_exists($this->user, 'getUsername')) {
                return $this->user->getUserIdentifier();
            }
        }

        return '';
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function setAuthenticated($isAuthenticated): void
    {
        $this->authenticated = (bool) $isAuthenticated;
    }

    public function eraseCredentials(): void
    {
        // no-op
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function hasAttribute($name): bool
    {
        return array_key_exists((string) $name, $this->attributes);
    }

    public function getAttribute($name): mixed
    {
        return $this->attributes[(string) $name] ?? null;
    }

    public function setAttribute($name, $value): void
    {
        $this->attributes[(string) $name] = $value;
    }

    // Legacy Serializable interface methods (required by Symfony 4.4 TokenInterface)
    public function serialize(): string
    {
        return serialize([
            'authenticated' => $this->authenticated,
            'attributes' => $this->attributes,
        ]);
    }

    public function unserialize($serialized): void
    {
        $data = unserialize((string) $serialized);
        $this->authenticated = (bool) ($data['authenticated'] ?? true);
        $this->attributes = (array) ($data['attributes'] ?? []);
    }

    // New PHP 7.4+/8.x serialization
    public function __serialize(): array
    {
        return [
            'authenticated' => $this->authenticated,
            'attributes' => $this->attributes,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->authenticated = (bool) ($data['authenticated'] ?? true);
        $this->attributes = (array) ($data['attributes'] ?? []);
    }
}
