<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Util\RequestEntityHelper;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for RequestEntityHelper.
 *
 * @internal
 */
#[CoversClass(RequestEntityHelper::class)]
final class RequestEntityHelperTest extends TestCase
{
    // ==================== id() tests ====================

    public function testIdReturnsStringValueFromRequest(): void
    {
        $request = $this->createRequestWithValue('entity_id', '123');

        $result = RequestEntityHelper::id($request, 'entity_id');

        self::assertSame('123', $result);
    }

    public function testIdReturnsNullForEmptyString(): void
    {
        $request = $this->createRequestWithValue('entity_id', '');

        $result = RequestEntityHelper::id($request, 'entity_id');

        self::assertNull($result);
    }

    public function testIdReturnsNullForMissingKey(): void
    {
        $request = $this->createRequestWithValue('other_key', '123');

        $result = RequestEntityHelper::id($request, 'entity_id');

        self::assertNull($result);
    }

    public function testIdReturnsNullForNullValue(): void
    {
        $request = $this->createRequestWithValue('entity_id', null);

        $result = RequestEntityHelper::id($request, 'entity_id');

        self::assertNull($result);
    }

    public function testIdConvertsIntToString(): void
    {
        $request = $this->createRequestWithValue('entity_id', 42);

        $result = RequestEntityHelper::id($request, 'entity_id');

        self::assertSame('42', $result);
    }

    public function testIdReturnsZeroAsValidString(): void
    {
        $request = $this->createRequestWithValue('entity_id', 0);

        $result = RequestEntityHelper::id($request, 'entity_id');

        self::assertSame('0', $result);
    }

    // ==================== findById() tests ====================

    public function testFindByIdReturnsNullForNullId(): void
    {
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::never())->method('getRepository');

        $result = RequestEntityHelper::findById($managerRegistry, User::class, null);

        self::assertNull($result);
    }

    public function testFindByIdReturnsEntityWhenFound(): void
    {
        $user = new User();
        $repository = $this->createMock(ServiceEntityRepository::class);
        $repository->method('find')->with('123')->willReturn($user);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $result = RequestEntityHelper::findById($managerRegistry, User::class, '123');

        self::assertSame($user, $result);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repository = $this->createMock(ServiceEntityRepository::class);
        $repository->method('find')->with('999')->willReturn(null);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $result = RequestEntityHelper::findById($managerRegistry, User::class, '999');

        self::assertNull($result);
    }

    public function testFindByIdReturnsNullWhenWrongEntityTypeReturned(): void
    {
        // Simulate a case where repository returns an unexpected object type
        $repository = $this->createMock(ServiceEntityRepository::class);
        $repository->method('find')->with('123')->willReturn(new stdClass());

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $result = RequestEntityHelper::findById($managerRegistry, User::class, '123');

        self::assertNull($result);
    }

    // ==================== user() tests ====================

    public function testUserReturnsUserEntity(): void
    {
        $user = new User();
        $repository = $this->createMock(ServiceEntityRepository::class);
        $repository->method('find')->with('123')->willReturn($user);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $request = $this->createRequestWithValue('user_id', '123');

        $result = RequestEntityHelper::user($request, $managerRegistry, 'user_id');

        self::assertSame($user, $result);
    }

    public function testUserReturnsNullWhenNotFound(): void
    {
        $repository = $this->createMock(ServiceEntityRepository::class);
        $repository->method('find')->willReturn(null);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $request = $this->createRequestWithValue('user_id', '999');

        $result = RequestEntityHelper::user($request, $managerRegistry, 'user_id');

        self::assertNull($result);
    }

    public function testUserReturnsNullForMissingKey(): void
    {
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::never())->method('getRepository');

        $request = $this->createRequestWithValue('other_key', '123');

        $result = RequestEntityHelper::user($request, $managerRegistry, 'user_id');

        self::assertNull($result);
    }

    // ==================== ticketSystem() tests ====================

    public function testTicketSystemReturnsTicketSystemEntity(): void
    {
        $ticketSystem = new TicketSystem();
        $repository = $this->createMock(ServiceEntityRepository::class);
        $repository->method('find')->with('456')->willReturn($ticketSystem);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')
            ->with(TicketSystem::class)
            ->willReturn($repository);

        $request = $this->createRequestWithValue('ticket_system_id', '456');

        $result = RequestEntityHelper::ticketSystem($request, $managerRegistry, 'ticket_system_id');

        self::assertSame($ticketSystem, $result);
    }

    public function testTicketSystemReturnsNullWhenNotFound(): void
    {
        $repository = $this->createMock(ServiceEntityRepository::class);
        $repository->method('find')->willReturn(null);

        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')
            ->with(TicketSystem::class)
            ->willReturn($repository);

        $request = $this->createRequestWithValue('ticket_system_id', '999');

        $result = RequestEntityHelper::ticketSystem($request, $managerRegistry, 'ticket_system_id');

        self::assertNull($result);
    }

    // ==================== Helper methods ====================

    private function createRequestWithValue(string $key, mixed $value): Request
    {
        $request = new Request();
        $request->request = new InputBag([$key => $value]);

        return $request;
    }
}
