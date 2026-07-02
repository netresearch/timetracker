<?php

declare(strict_types=1);

namespace Tests\Command;

use App\Command\CreateUserCommand;
use App\Entity\User;
use App\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
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
    /** Captures the entity handed to EntityManager::persist(). */
    private ?User $persisted = null;

    public function testCreatesNewLocalUserWithHashedPassword(): void
    {
        $tester = new CommandTester($this->makeCommand(null, '$2y$hashed-value'));
        $status = $tester->execute(['username' => 'root', '--type' => 'ADMIN', '--password' => 'sup3rsecret']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertInstanceOf(User::class, $this->persisted);
        self::assertSame('root', $this->persisted->getUsername());
        self::assertTrue($this->persisted->isLocalAccount());
        self::assertSame('$2y$hashed-value', $this->persisted->getPassword());
    }

    public function testResetsExistingUserPassword(): void
    {
        $existing = new User()->setUsername('jane')->setType('DEV');

        $tester = new CommandTester($this->makeCommand($existing, '$2y$new-hash'));
        $status = $tester->execute(['username' => 'jane', '--type' => 'PL', '--password' => 'rotate-me']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($existing, $this->persisted);
        self::assertSame('$2y$new-hash', $existing->getPassword());
    }

    public function testResettingExistingUserPasswordWithoutTypePreservesType(): void
    {
        // Regression guard: the command doubles as a password reset. Omitting
        // --type must NOT re-type the user — running it for a DEV must not promote
        // to ADMIN just because ADMIN is the new-user default (privilege escalation).
        $existing = new User()->setUsername('jane')->setType('DEV');

        $tester = new CommandTester($this->makeCommand($existing, '$2y$new-hash'));
        $status = $tester->execute(['username' => 'jane', '--password' => 'rotate-me']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame($existing, $this->persisted);
        self::assertSame(UserType::DEV, $existing->getType());
        self::assertSame('$2y$new-hash', $existing->getPassword());
    }

    public function testNewUserWithoutTypeDefaultsToAdmin(): void
    {
        $tester = new CommandTester($this->makeCommand(null, '$2y$hashed-value'));
        $status = $tester->execute(['username' => 'root', '--password' => 'sup3rsecret']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertInstanceOf(User::class, $this->persisted);
        self::assertSame(UserType::ADMIN, $this->persisted->getType());
    }

    public function testRejectsInvalidType(): void
    {
        $tester = new CommandTester($this->makeCommand(null, '$2y$hash'));
        $status = $tester->execute(['username' => 'x', '--type' => 'BOGUS', '--password' => 'pw']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('Invalid --type', $tester->getDisplay());
    }

    /**
     * Builds the command wired to a stub EntityManager (whose persist() records
     * the entity into $this->persisted) and a stub password hasher.
     */
    private function makeCommand(?User $existing, string $hash): Command
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->userRepositoryReturning($existing));
        $entityManager->method('persist')->willReturnCallback(function (object $object): void {
            if ($object instanceof User) {
                $this->persisted = $object;
            }
        });

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn($hash);

        return $this->wrap(new CreateUserCommand($entityManager, $hasher));
    }

    /** Wraps the invokable command in a Command so its #[Argument]/#[Option] attributes build the input definition. */
    private function wrap(CreateUserCommand $invokable): Command
    {
        $command = new Command('app:user:create');
        $command->setCode($invokable);

        return $command;
    }

    private function userRepositoryReturning(?User $user): EntityRepository
    {
        $repository = self::createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($user);

        return $repository;
    }
}
