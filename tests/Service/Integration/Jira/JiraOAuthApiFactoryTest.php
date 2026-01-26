<?php

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * Unit tests for JiraOAuthApiFactory.
 *
 * @internal
 */
#[CoversClass(JiraOAuthApiFactory::class)]
final class JiraOAuthApiFactoryTest extends TestCase
{
    public function testSetDependenciesStoresDependencies(): void
    {
        $factory = new JiraOAuthApiFactory();
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $router = $this->createMock(RouterInterface::class);

        $factory->setDependencies($managerRegistry, $router);

        self::assertSame($managerRegistry, $factory->managerRegistry);
        self::assertSame($router, $factory->router);
    }

    public function testCreateReturnsJiraOAuthApiService(): void
    {
        // The create method returns JiraOAuthApiService by type declaration.
        // We test that it doesn't throw and creates a valid service.
        $this->expectNotToPerformAssertions();

        $factory = new JiraOAuthApiFactory();
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $router = $this->createMock(RouterInterface::class);
        $factory->setDependencies($managerRegistry, $router);

        $user = $this->createMock(User::class);
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getUrl')->willReturn('https://jira.example.com');

        $factory->create($user, $ticketSystem);
    }
}
