<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\PersonioConfig;
use App\Entity\User;
use App\Repository\PersonioConfigRepository;
use App\Repository\UserRepository;
use App\Service\Personio\EmployeeMatcher;
use App\Service\Personio\PersonioClientFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function ctype_digit;
use function sprintf;

/**
 * ADR-024 P3: proposes (and, with --apply, writes) TT user -> Personio employee-id
 * matches, so the manual mapping the export/import rely on can be filled in
 * automatically. Matches by e-mail localpart or firstname.lastname; ambiguous
 * usernames are skipped, never guessed. Preview by default.
 */
#[AsCommand(name: 'tt:match-personio-employees', description: 'Auto-match TT users to Personio employee ids (ADR-024 P3)')]
class TtMatchPersonioEmployeesCommand extends Command
{
    public function __construct(
        private readonly PersonioConfigRepository $configRepository,
        private readonly PersonioClientFactory $clientFactory,
        private readonly UserRepository $userRepository,
        private readonly EmployeeMatcher $employeeMatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option(description: 'Write the matched employee ids (default: preview only)', name: 'apply')]
        bool $apply = false,
    ): int {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $config = $this->configRepository->findActive();
        if (!$config instanceof PersonioConfig) {
            $symfonyStyle->error('No active Personio configuration.');

            return 1;
        }

        $users = $this->userRepository->findWithoutPersonioEmployeeId();
        if ([] === $users) {
            $symfonyStyle->note('Every user already has a Personio employee id.');

            return Command::SUCCESS;
        }

        $matches = $this->employeeMatcher->match($users, $this->clientFactory->create($config)->listPersons());
        if ([] === $matches) {
            $symfonyStyle->note('No confident matches for the ' . count($users) . ' unmapped user(s).');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($matches as $match) {
            $rows[] = [$match->username, $match->personId, $match->personName, $match->source];
        }
        $symfonyStyle->table(['User', 'Personio id', 'Personio name', 'Matched by'], $rows);

        if (!$apply) {
            $symfonyStyle->note('Preview only — re-run with --apply to write these employee ids.');

            return Command::SUCCESS;
        }

        $usersById = [];
        foreach ($users as $user) {
            $usersById[(int) $user->getId()] = $user;
        }

        $applied = 0;
        foreach ($matches as $match) {
            // Personio employee ids are numeric (the column is a bigint); skip a
            // non-numeric id rather than truncate it to a wrong value.
            if (!ctype_digit($match->personId)) {
                $symfonyStyle->warning(sprintf('Skipped %s: non-numeric Personio id "%s".', $match->username, $match->personId));

                continue;
            }

            $user = $usersById[$match->userId] ?? null;
            if ($user instanceof User) {
                $user->setPersonioEmployeeId((int) $match->personId);
                ++$applied;
            }
        }

        $this->entityManager->flush();
        $symfonyStyle->success(sprintf('Applied %d employee-id mapping(s).', $applied));

        return Command::SUCCESS;
    }
}
