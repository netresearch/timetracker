<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Service\ApiToken\ApiTokenService;
use App\Service\ClockInterface;
use App\ValueObject\ApiScope;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function implode;
use function sprintf;
use function strtotime;

/**
 * Mint an API personal access token for a user (ADR-021), for cron/bootstrap use.
 * The plaintext is printed ONCE — it is not recoverable afterwards. Scopes narrow
 * the user's access; enforcement is the Bearer firewall (Phase 2).
 */
#[AsCommand(name: 'app:api-token:create', description: 'Create an API personal access token for a user')]
final readonly class CreateApiTokenCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ApiTokenService $apiTokenService,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string> $scope
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Login username the token acts as')]
        string $username,
        #[Argument(description: 'A label for the token (e.g. "ci-worklog")')]
        string $name,
        #[Option(description: 'Scope in resource:action form; repeatable. Use "*" for all the user can grant.', name: 'scope')]
        array $scope = [],
        #[Option(description: 'Optional expiry, e.g. "+90 days" or "2026-12-31"')]
        ?string $expires = null,
    ): int {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$user instanceof User) {
            $io->error(sprintf('No user "%s".', $username));

            return Command::FAILURE;
        }

        if ([] === $scope) {
            $io->error('At least one --scope is required. Valid scopes: ' . implode(', ', ApiScope::all()));

            return Command::FAILURE;
        }

        $expiresAt = null;
        if (null !== $expires && '' !== $expires) {
            $timestamp = strtotime($expires, $this->clock->now()->getTimestamp());
            if (false === $timestamp) {
                $io->error('Invalid --expires. Use e.g. "+90 days" or "2026-12-31".');

                return Command::FAILURE;
            }

            $expiresAt = new DateTimeImmutable()->setTimestamp($timestamp);
        }

        try {
            [$token, $plaintext] = $this->apiTokenService->create($user, $name, $scope, $expiresAt);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $io->error($invalidArgumentException->getMessage() . ' Valid scopes: ' . implode(', ', ApiScope::all()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Created token "%s" for %s (scopes: %s).', $name, $username, implode(', ', $token->getScopes())));
        $io->writeln('Token (shown once — store it now):');
        $io->writeln('  <info>' . $plaintext . '</info>');

        return Command::SUCCESS;
    }
}
