<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\TicketSystem;
use App\Enum\TicketSystemType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TicketSystem entity.
 *
 * @internal
 */
#[CoversClass(TicketSystem::class)]
final class TicketSystemTest extends TestCase
{
    // ==================== ID tests ====================

    public function testIdIsNullByDefault(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertNull($ticketSystem->getId());
    }

    // ==================== Name tests ====================

    public function testSetNameReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setName('Jira Cloud');

        self::assertSame($ticketSystem, $result);
        self::assertSame('Jira Cloud', $ticketSystem->getName());
    }

    // ==================== BookTime tests ====================

    public function testBookTimeIsFalseByDefault(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertFalse($ticketSystem->getBookTime());
    }

    public function testSetBookTimeReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setBookTime(true);

        self::assertSame($ticketSystem, $result);
        self::assertTrue($ticketSystem->getBookTime());
    }

    // ==================== Type tests ====================

    public function testTypeIsJiraByDefault(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertSame(TicketSystemType::JIRA, $ticketSystem->getType());
        self::assertSame('JIRA', $ticketSystem->getTypeRaw());
    }

    public function testSetTypeWithEnumReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setType(TicketSystemType::OTRS);

        self::assertSame($ticketSystem, $result);
        self::assertSame(TicketSystemType::OTRS, $ticketSystem->getType());
    }

    public function testSetTypeWithStringReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setType('CUSTOM_TYPE');

        self::assertSame($ticketSystem, $result);
        self::assertSame(TicketSystemType::UNKNOWN, $ticketSystem->getType());
        self::assertSame('CUSTOM_TYPE', $ticketSystem->getTypeRaw());
    }

    // ==================== URL tests ====================

    public function testSetUrlReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setUrl('https://jira.example.com');

        self::assertSame($ticketSystem, $result);
        self::assertSame('https://jira.example.com', $ticketSystem->getUrl());
    }

    // ==================== TicketUrl tests ====================

    public function testSetTicketUrlReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setTicketUrl('https://jira.example.com/browse/');

        self::assertSame($ticketSystem, $result);
        self::assertSame('https://jira.example.com/browse/', $ticketSystem->getTicketUrl());
    }

    // ==================== Login tests ====================

    public function testSetLoginReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setLogin('admin');

        self::assertSame($ticketSystem, $result);
        self::assertSame('admin', $ticketSystem->getLogin());
    }

    // ==================== Password tests ====================

    public function testSetPasswordReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setPassword('secret123');

        self::assertSame($ticketSystem, $result);
        self::assertSame('secret123', $ticketSystem->getPassword());
    }

    // ==================== PublicKey tests ====================

    public function testPublicKeyIsEmptyByDefault(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertSame('', $ticketSystem->getPublicKey());
    }

    public function testSetPublicKeyReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setPublicKey('-----BEGIN PUBLIC KEY-----');

        self::assertSame($ticketSystem, $result);
        self::assertSame('-----BEGIN PUBLIC KEY-----', $ticketSystem->getPublicKey());
    }

    // ==================== PrivateKey tests ====================

    public function testPrivateKeyIsEmptyByDefault(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertSame('', $ticketSystem->getPrivateKey());
    }

    public function testSetPrivateKeyReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setPrivateKey('-----BEGIN PRIVATE KEY-----');

        self::assertSame($ticketSystem, $result);
        self::assertSame('-----BEGIN PRIVATE KEY-----', $ticketSystem->getPrivateKey());
    }

    // ==================== OAuthConsumerKey tests ====================

    public function testOauthConsumerKeyIsNullByDefault(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertNull($ticketSystem->getOauthConsumerKey());
    }

    public function testSetOauthConsumerKeyReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setOauthConsumerKey('consumer-key-123');

        self::assertSame($ticketSystem, $result);
        self::assertSame('consumer-key-123', $ticketSystem->getOauthConsumerKey());
    }

    // ==================== OAuthConsumerSecret tests ====================

    public function testOauthConsumerSecretIsNullByDefault(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertNull($ticketSystem->getOauthConsumerSecret());
    }

    public function testSetOauthConsumerSecretReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setOauthConsumerSecret('consumer-secret-456');

        self::assertSame($ticketSystem, $result);
        self::assertSame('consumer-secret-456', $ticketSystem->getOauthConsumerSecret());
    }
}
