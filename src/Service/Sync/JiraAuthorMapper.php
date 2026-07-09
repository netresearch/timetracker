<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Sync;

use App\DTO\Jira\JiraWorkLog;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use Doctrine\ORM\EntityManagerInterface;

use function strlen;
use function strstr;
use function substr;

/**
 * Maps Jira worklog authors to TT users (ADR-023 §3): durable remote_account_id mapping,
 * auto-match by username (Jira name / email localpart), shadow-user creation for unknowns.
 */
class JiraAuthorMapper
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function remoteKey(JiraWorkLog $jiraWorkLog): ?string
    {
        return $jiraWorkLog->authorAccountId ?? $jiraWorkLog->authorName ?? $jiraWorkLog->authorEmail;
    }

    public function find(JiraWorkLog $jiraWorkLog, TicketSystem $ticketSystem): ?User
    {
        foreach ([$jiraWorkLog->authorAccountId, $jiraWorkLog->authorName] as $remoteId) {
            if (null === $remoteId) {
                continue;
            }
            if ('' === $remoteId) {
                continue;
            }
            $mapping = $this->entityManager->getRepository(UserTicketsystem::class)
                ->findOneBy(['ticketSystem' => $ticketSystem, 'remoteAccountId' => $remoteId]);
            if ($mapping instanceof UserTicketsystem && $mapping->getUser() instanceof User) {
                return $mapping->getUser();
            }
        }

        foreach ([$jiraWorkLog->authorName, $this->emailLocalpart($jiraWorkLog->authorEmail)] as $candidate) {
            if (null === $candidate) {
                continue;
            }
            if ('' === $candidate) {
                continue;
            }
            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $candidate]);
            if ($user instanceof User) {
                $this->persistMapping($user, $jiraWorkLog, $ticketSystem);

                return $user;
            }
        }

        return null;
    }

    public function createShadow(JiraWorkLog $jiraWorkLog, TicketSystem $ticketSystem): User
    {
        $base = $jiraWorkLog->authorName
            ?? $this->emailLocalpart($jiraWorkLog->authorEmail)
            ?? 'jira-' . substr((string) $jiraWorkLog->authorAccountId, 0, 43);
        $base = substr($base, 0, 50);

        $username = $base;
        $suffix = 2;
        while ($this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]) instanceof User) {
            $username = substr($base, 0, 50 - strlen((string) $suffix) - 1) . '-' . $suffix;
            ++$suffix;
        }

        $shadow = new User();
        $shadow->setUsername($username);
        $shadow->setActive(false);
        $this->entityManager->persist($shadow);

        $this->persistMapping($shadow, $jiraWorkLog, $ticketSystem, alwaysCreate: true);

        return $shadow;
    }

    private function persistMapping(User $user, JiraWorkLog $jiraWorkLog, TicketSystem $ticketSystem, bool $alwaysCreate = false): void
    {
        $remoteId = $this->remoteKey($jiraWorkLog);
        if (null === $remoteId) {
            return;
        }

        if (!$alwaysCreate) {
            $existing = $this->entityManager->getRepository(UserTicketsystem::class)
                ->findOneBy(['ticketSystem' => $ticketSystem, 'user' => $user]);
            if ($existing instanceof UserTicketsystem) {
                $existing->setRemoteAccountId($remoteId);

                return;
            }
        }

        $mapping = new UserTicketsystem();
        $mapping->setUser($user);
        $mapping->setTicketSystem($ticketSystem);
        $mapping->setAccessToken('');
        $mapping->setTokenSecret('');
        $mapping->setRemoteAccountId($remoteId);
        $this->entityManager->persist($mapping);
    }

    private function emailLocalpart(?string $email): ?string
    {
        if (null === $email) {
            return null;
        }

        $localpart = strstr($email, '@', true);

        return false === $localpart || '' === $localpart ? null : $localpart;
    }
}
