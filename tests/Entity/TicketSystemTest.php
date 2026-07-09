<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Activity;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\DeploymentType;
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

    // ==================== toSafeArray tests ====================

    public function testToSafeArrayOmitsEverySecretKeyInBothSpellings(): void
    {
        $ticketSystem = new TicketSystem();
        $ticketSystem->setName('Jira')
            ->setUrl('https://jira.example.test')
            ->setLogin('svc')
            ->setPassword('s3cr3t')
            ->setPublicKey('pub')
            ->setPrivateKey('priv')
            ->setOauthConsumerKey('ck')
            ->setOauthConsumerSecret('cs')
            ->setOauth2ClientId('c2id')
            ->setOauth2ClientSecret('c2secret')
            ->setCloudId('cloud-123')
            ->setDeploymentType(DeploymentType::CLOUD);

        $safe = $ticketSystem->toSafeArray();

        foreach (TicketSystem::SECRET_KEYS as $secretKey) {
            self::assertArrayNotHasKey($secretKey, $safe);
        }

        self::assertArrayNotHasKey('password', $safe);
        // Non-secret fields survive.
        self::assertSame('Jira', $safe['name']);
        self::assertSame('https://jira.example.test', $safe['url']);
        self::assertSame('svc', $safe['login']);
        // No stored secret value leaks under any key.
        self::assertNotContains('s3cr3t', $safe);
        self::assertNotContains('priv', $safe);
        self::assertNotContains('cs', $safe);
    }

    public function testToSafeArrayStripsOauth2ClientSecretInBothSpellings(): void
    {
        $ticketSystem = new TicketSystem();
        $ticketSystem->setName('Jira Cloud')
            ->setUrl('https://acme.atlassian.net')
            ->setLogin('svc')
            ->setPassword('pw')
            ->setOauth2ClientId('client-id-abc')
            ->setOauth2ClientSecret('client-secret-xyz')
            ->setCloudId('11111111-2222-3333-4444-555555555555')
            ->setDeploymentType(DeploymentType::CLOUD);

        $safe = $ticketSystem->toSafeArray();

        // The OAuth2 client secret is stripped in both spellings.
        self::assertArrayNotHasKey('oauth2ClientSecret', $safe);
        self::assertArrayNotHasKey('oauth2_client_secret', $safe);
        self::assertNotContains('client-secret-xyz', $safe);

        // The non-secret OAuth2/deployment/cloud fields survive (both spellings).
        self::assertSame('client-id-abc', $safe['oauth2ClientId']);
        self::assertSame('client-id-abc', $safe['oauth2_client_id']);
        self::assertSame('CLOUD', $safe['deploymentType']);
        self::assertSame('CLOUD', $safe['deployment_type']);
        self::assertSame('11111111-2222-3333-4444-555555555555', $safe['cloudId']);
        self::assertSame('11111111-2222-3333-4444-555555555555', $safe['cloud_id']);
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

    // ==================== DeploymentType tests ====================

    public function testDeploymentTypeIsServerByDefault(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertSame(DeploymentType::SERVER, $ticketSystem->getDeploymentType());
        self::assertSame('SERVER', $ticketSystem->getDeploymentTypeRaw());
    }

    public function testSetDeploymentTypeWithEnumReturnsFluentInterface(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setDeploymentType(DeploymentType::CLOUD);

        self::assertSame($ticketSystem, $result);
        self::assertSame(DeploymentType::CLOUD, $ticketSystem->getDeploymentType());
        self::assertSame('CLOUD', $ticketSystem->getDeploymentTypeRaw());
    }

    public function testSetDeploymentTypeWithStringFallsBackToServerForUnknown(): void
    {
        $ticketSystem = new TicketSystem();

        $result = $ticketSystem->setDeploymentType('CUSTOM');

        self::assertSame($ticketSystem, $result);
        self::assertSame(DeploymentType::SERVER, $ticketSystem->getDeploymentType());
        self::assertSame('CUSTOM', $ticketSystem->getDeploymentTypeRaw());
    }

    // ===== Nullable string accessors (oauth2 client id/secret, cloud id) =====

    public function testNullableStringAccessorsDefaultToNullAndRoundTrip(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertNull($ticketSystem->getOauth2ClientId());
        self::assertNull($ticketSystem->getOauth2ClientSecret());
        self::assertNull($ticketSystem->getCloudId());

        self::assertSame($ticketSystem, $ticketSystem->setOauth2ClientId('client-id-123'));
        self::assertSame($ticketSystem, $ticketSystem->setOauth2ClientSecret('client-secret-456'));
        self::assertSame($ticketSystem, $ticketSystem->setCloudId('cloud-789'));

        self::assertSame('client-id-123', $ticketSystem->getOauth2ClientId());
        self::assertSame('client-secret-456', $ticketSystem->getOauth2ClientSecret());
        self::assertSame('cloud-789', $ticketSystem->getCloudId());
    }

    // ==================== Sync configuration tests ====================

    public function testSyncConfigurationAccessors(): void
    {
        $ticketSystem = new TicketSystem();

        self::assertNull($ticketSystem->getSyncUser());
        self::assertNull($ticketSystem->getSyncDefaultActivity());
        self::assertNull($ticketSystem->getWorklogSyncCursor());

        $user = new User();
        $activity = new Activity();
        $ticketSystem->setSyncUser($user)->setSyncDefaultActivity($activity)->setWorklogSyncCursor(1751871600000);

        self::assertSame($user, $ticketSystem->getSyncUser());
        self::assertSame($activity, $ticketSystem->getSyncDefaultActivity());
        self::assertSame(1751871600000, $ticketSystem->getWorklogSyncCursor());
    }
}
