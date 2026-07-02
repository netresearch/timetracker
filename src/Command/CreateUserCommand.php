<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use function is_string;
use function sprintf;

/**
 * Creates a local (password) user or (re)sets an existing user's password —
 * the bootstrap for a local-only install and the LDAP-outage escape hatch
 * (ADR-018 D1). The password is hashed via the configured `auto` hasher and
 * never logged.
 *
 * Invokable, attribute-driven input (Symfony 8): no configure()/getOption(),
 * which also keeps the phpstan-symfony console analyser from booting the app.
 */
#[AsCommand(name: 'app:user:create', description: 'Create a local password user or reset a user password')]
final readonly class CreateUserCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Login username')]
        ?string $username = null,
        #[Option(description: 'User type: USER, DEV, PL or ADMIN')]
        string $type = 'ADMIN',
        #[Option(description: 'Password (omit to be prompted; prompting keeps it out of shell history)')]
        #[SensitiveParameter]
        ?string $password = null,
    ): int {
        if (null === $username) {
            $answer = $io->ask('Username');
            $username = is_string($answer) ? $answer : '';
        }

        if ('' === $username) {
            $io->error('A username is required.');

            return Command::FAILURE;
        }

        $userType = UserType::tryFrom($type);
        if (!$userType instanceof UserType || UserType::UNKNOWN === $userType) {
            $io->error('Invalid --type. Use one of: USER, DEV, PL, ADMIN.');

            return Command::FAILURE;
        }

        if (null === $password) {
            $answer = $io->askHidden('Password');
            $password = is_string($answer) ? $answer : '';
        }

        if ('' === $password) {
            $io->error('A non-empty password is required.');

            return Command::FAILURE;
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        $isNew = !$user instanceof User;
        if ($isNew) {
            $user = new User()
                ->setUsername($username)
                ->setType($userType->value)
                ->setLocale('en');
        } else {
            $user->setType($userType->value);
        }

        $this->setHashedPassword($user, $password);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s local user "%s" (%s).',
            $isNew ? 'Created' : 'Updated password for',
            $username,
            $userType->value,
        ));

        return Command::SUCCESS;
    }

    private function setHashedPassword(User $user, #[SensitiveParameter] string $plainPassword): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
    }
}
