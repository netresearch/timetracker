<?php

declare(strict_types=1);

namespace Tests\Command;

use App\Command\CreateUserCommand;
use App\Entity\User;
use App\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @internal
 */
#[CoversClass(CreateUserCommand::class)]
final class CreateUserCommandTest extends TestCase
{
    public function testCreatesNewLocalUserWithHashedPassword(): void
    {
        $persisted = null;
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->userRepositoryReturning(null));
        $entityManager->method('persist')->willReturnCallback(static function (object $object) use (&$persisted): void {
            $persisted = $object;
        });

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('$2y$hashed-value');

        $tester = new CommandTester($this->wrap(new CreateUserCommand($entityManager, $hasher)));
        $status = $tester->execute(['username' => 'root', '--type' => 'ADMIN', '--password' => 'sup3rsecret']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertInstanceOf(User::class, $persisted);
        self::assertSame('root', $persisted->getUsername());
        self::assertTrue($persisted->isLocalAccount());
        self::assertSame('$2y$hashed-value', $persisted->getPassword());
    }

    public function testResetsExistingUserPassword(): void
    {
        $existing = new User()->setUsername('jane')->setType('DEV');

        $persisted = null;
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->userRepositoryReturning($existing));
        $entityManager->method('persist')->willReturnCallback(static function (object $object) use (&$persisted): void {
            $persisted = $object;
        });

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('$2y$new-hash');

        $tester = new CommandTester($this->wrap(new CreateUserCommand($entityManager, $hasher)));
        $status = $tester->execute(['username' => 'jane', '--type' => 'PL', '--password' => 'rotate-me']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($existing, $persisted);
        self::assertSame('$2y$new-hash', $existing->getPassword());
    }

    public function testResettingExistingUserPasswordWithoutTypePreservesType(): void
    {
        // Regression guard: the command doubles as a password reset. Omitting
        // --type must NOT re-type the user — running it for a DEV must not promote
        // to ADMIN just because ADMIN is the new-user default (privilege escalation).
        $existing = new User()->setUsername('jane')->setType('DEV');

        $persisted = null;
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->userRepositoryReturning($existing));
        $entityManager->method('persist')->willReturnCallback(static function (object $object) use (&$persisted): void {
            $persisted = $object;
        });

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('$2y$new-hash');

        $tester = new CommandTester($this->wrap(new CreateUserCommand($entityManager, $hasher)));
        $status = $tester->execute(['username' => 'jane', '--password' => 'rotate-me']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($existing, $persisted);
        self::assertSame(UserType::DEV, $existing->getType());
        self::assertSame('$2y$new-hash', $existing->getPassword());
    }

    public function testNewUserWithoutTypeDefaultsToAdmin(): void
    {
        $persisted = null;
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->userRepositoryReturning(null));
        $entityManager->method('persist')->willReturnCallback(static function (object $object) use (&$persisted): void {
            $persisted = $object;
        });

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('$2y$hashed-value');

        $tester = new CommandTester($this->wrap(new CreateUserCommand($entityManager, $hasher)));
        $status = $tester->execute(['username' => 'root', '--password' => 'sup3rsecret']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertInstanceOf(User::class, $persisted);
        self::assertSame(UserType::ADMIN, $persisted->getType());
    }

    public function testRejectsInvalidType(): void
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $hasher = self::createStub(UserPasswordHasherInterface::class);

        $tester = new CommandTester($this->wrap(new CreateUserCommand($entityManager, $hasher)));
        $status = $tester->execute(['username' => 'x', '--type' => 'BOGUS', '--password' => 'pw']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Invalid --type', $tester->getDisplay());
    }

    /** Wraps the invokable command in a Command so its #[Argument]/#[Option] attributes build the input definition. */
    private function wrap(CreateUserCommand $invokable): Command
    {
        $command = new Command('app:user:create');
        $command->setCode($invokable);

        return $command;
    }

    private function userRepositoryReturning(?User $user): \Doctrine\ORM\EntityRepository
    {
        $repository = self::createStub(\Doctrine\ORM\EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        return $repository;
    }
}
