<?php

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\DeploymentType;
use App\Service\FrozenClock;
use App\Service\Integration\Jira\JiraCloudApiService;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;
use Tests\Traits\TokenEncryptionTestTrait;

/**
 * Unit tests for JiraOAuthApiFactory.
 *
 * @internal
 */
#[CoversClass(JiraOAuthApiFactory::class)]
final class JiraOAuthApiFactoryTest extends TestCase
{
    use TokenEncryptionTestTrait;

    public function testSetDependenciesStoresDependencies(): void
    {
        $factory = new JiraOAuthApiFactory();
        $managerRegistry = self::createStub(ManagerRegistry::class);
        $router = self::createStub(RouterInterface::class);
        $tokenEncryptionService = $this->createTokenEncryptionService();
        $clock = new FrozenClock('2026-07-02 12:00:00');

        $factory->setDependencies($managerRegistry, $router, $tokenEncryptionService, $clock);

        self::assertSame($managerRegistry, $factory->managerRegistry);
        self::assertSame($router, $factory->router);
        self::assertSame($tokenEncryptionService, $factory->tokenEncryptionService);
        self::assertSame($clock, $factory->clock);
    }

    public function testCreateReturnsServerServiceForServerDeployment(): void
    {
        $factory = $this->createFactory();

        $user = self::createStub(User::class);
        $ticketSystem = self::createStub(TicketSystem::class);
        $ticketSystem->method('getUrl')->willReturn('https://jira.example.com');
        $ticketSystem->method('getDeploymentType')->willReturn(DeploymentType::SERVER);

        $service = $factory->create($user, $ticketSystem);

        self::assertNotInstanceOf(JiraCloudApiService::class, $service);
    }

    public function testCreateReturnsCloudServiceForCloudDeployment(): void
    {
        $factory = $this->createFactory();

        $user = self::createStub(User::class);
        $ticketSystem = self::createStub(TicketSystem::class);
        $ticketSystem->method('getUrl')->willReturn('https://example.atlassian.net');
        $ticketSystem->method('getDeploymentType')->willReturn(DeploymentType::CLOUD);

        $service = $factory->create($user, $ticketSystem);

        self::assertInstanceOf(JiraCloudApiService::class, $service);
    }

    private function createFactory(): JiraOAuthApiFactory
    {
        $factory = new JiraOAuthApiFactory();
        $factory->setDependencies(
            self::createStub(ManagerRegistry::class),
            self::createStub(RouterInterface::class),
            $this->createTokenEncryptionService(),
            new FrozenClock('2026-07-02 12:00:00'),
        );

        return $factory;
    }
}
