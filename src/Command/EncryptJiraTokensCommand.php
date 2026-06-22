<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\UserTicketsystem;
use App\Service\Security\TokenEncryptionService;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

/**
 * One-time, idempotent migration of any plaintext Jira OAuth tokens to
 * encryption-at-rest. The OAuth 1.0a access tokens are long-lived and rarely
 * refresh, so they would otherwise stay plaintext indefinitely even though
 * JiraOAuthApiService now encrypts on write. Run once after deploying that
 * change; safe to run repeatedly (already-encrypted tokens are skipped).
 */
#[AsCommand(name: 'tt:encrypt-jira-tokens', description: 'Encrypt any plaintext Jira OAuth tokens stored at rest (idempotent)')]
class EncryptJiraTokensCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TokenEncryptionService $tokenEncryptionService,
    ) {
        parent::__construct();
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        /** @var UserTicketsystem[] $records */
        $records = $this->entityManager->getRepository(UserTicketsystem::class)->findAll();

        $encrypted = 0;
        foreach ($records as $record) {
            $changed = false;

            $secret = $record->getTokenSecret();
            if ('' !== $secret && $this->isPlaintext($secret)) {
                $record->setTokenSecret($this->tokenEncryptionService->encryptToken($secret));
                $changed = true;
            }

            $token = $record->getAccessToken();
            if ('' !== $token && $this->isPlaintext($token)) {
                $record->setAccessToken($this->tokenEncryptionService->encryptToken($token));
                $changed = true;
            }

            if ($changed) {
                ++$encrypted;
            }
        }

        if ($encrypted > 0) {
            $this->entityManager->flush();
        }

        $symfonyStyle->success(sprintf(
            'Encrypted plaintext tokens for %d of %d user/ticket-system record(s).',
            $encrypted,
            count($records),
        ));

        return Command::SUCCESS;
    }

    /**
     * A stored value is plaintext if it does not decrypt. AES-256-GCM's auth tag
     * makes a false negative impossible — random/plaintext bytes can't forge a
     * valid tag — so a decrypt failure reliably means "not yet encrypted".
     */
    private function isPlaintext(string $value): bool
    {
        try {
            $this->tokenEncryptionService->decryptToken($value);

            return false;
        } catch (Exception) {
            return true;
        }
    }
}
