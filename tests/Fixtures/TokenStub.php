<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_key_exists;
use function assert;
use function is_array;

class TokenStub implements TokenInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $attributes = [];

    private bool $authenticated = true;

    public function __construct(private ?UserInterface $user = null)
    {
    }

    public function __toString(): string
    {
        return 'token-stub';
    }

    /**
     * @return array<int, string>
     */
    public function getRoles(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
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
        return $this->user?->getUserIdentifier() ?? '';
    }

    public function getUserIdentifier(): string
    {
        return $this->user?->getUserIdentifier() ?? '';
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function setAuthenticated(bool $isAuthenticated): void
    {
        $this->authenticated = $isAuthenticated;
    }

    public function eraseCredentials(): void
    {
        // no-op
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<mixed, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        // Filter to only string keys for type safety
        $filtered = [];
        foreach ($attributes as $key => $value) {
            if (\is_string($key)) {
                $filtered[$key] = $value;
            }
        }
        $this->attributes = $filtered;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    // Legacy Serializable interface methods (required by Symfony 4.4 TokenInterface)
    public function serialize(): string
    {
        return serialize([
            'authenticated' => $this->authenticated,
            'attributes' => $this->attributes,
        ]);
    }

    public function unserialize(string $serialized): void
    {
        $data = unserialize($serialized);
        assert(is_array($data));
        $this->authenticated = (bool) ($data['authenticated'] ?? true);
        $attributes = $data['attributes'] ?? [];
        if (is_array($attributes)) {
            $this->attributes = $attributes;
        } else {
            $this->attributes = [];
        }
    }

    // New PHP 7.4+/8.x serialization
    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'authenticated' => $this->authenticated,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * @param array<mixed, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->authenticated = (bool) ($data['authenticated'] ?? true);
        $attributes = $data['attributes'] ?? [];
        if (is_array($attributes)) {
            /** @var array<string, mixed> $typedAttributes */
            $typedAttributes = $attributes;
            $this->attributes = $typedAttributes;
        } else {
            $this->attributes = [];
        }
    }
}
