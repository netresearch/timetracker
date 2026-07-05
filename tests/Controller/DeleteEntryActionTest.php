<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AbstractWebTestCase;

use const JSON_THROW_ON_ERROR;

/**
 * Covers the DeleteEntryAction fixes: it reads the id from a JSON body (not only
 * form params), rejects a missing id instead of silently succeeding, and enforces
 * entry ownership (a developer cannot delete another user's entry).
 *
 * @internal
 *
 * @coversNothing
 */
final class DeleteEntryActionTest extends AbstractWebTestCase
{
    private const string JSON_MIME = 'application/json';

    private function entityManager(): EntityManagerInterface
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(Registry::class, $doctrine);
        $manager = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function user(string $username): User
    {
        $user = $this->entityManager()->getRepository(User::class)->findOneBy(['username' => $username]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /** Persist a minimal entry owned by $username and return it. */
    private function makeEntry(string $username): Entry
    {
        $entityManager = $this->entityManager();
        $customer = $entityManager->getRepository(Customer::class)->findOneBy([]);
        $project = $entityManager->getRepository(Project::class)->findOneBy([]);
        $activity = $entityManager->getRepository(Activity::class)->findOneBy([]);
        self::assertInstanceOf(Customer::class, $customer);
        self::assertInstanceOf(Project::class, $project);
        self::assertInstanceOf(Activity::class, $activity);

        $entry = new Entry();
        $entry->setUser($this->user($username))
            ->setCustomer($customer)
            ->setProject($project)
            ->setActivity($activity)
            ->setTicket('')
            ->setDescription('delete-test')
            ->setDay('2024-01-15')
            ->setStart('09:00:00')
            ->setEnd('10:00:00')
            ->setDuration(60);
        $entityManager->persist($entry);
        $entityManager->flush();

        return $entry;
    }

    private function deleteJson(int $id): Response
    {
        $this->client->request(
            Request::METHOD_POST,
            '/tracking/delete',
            [],
            [],
            ['CONTENT_TYPE' => self::JSON_MIME, 'HTTP_ACCEPT' => self::JSON_MIME],
            json_encode(['id' => $id], JSON_THROW_ON_ERROR),
        );

        return $this->client->getResponse();
    }

    private function entryExists(int $id): bool
    {
        return null !== $this->entityManager()->getRepository(Entry::class)->find($id);
    }

    public function testDeletesOwnEntryFromJsonBody(): void
    {
        // unittest is the default logged-in user; delete via a JSON body (the old
        // code read form params only, so a JSON id was silently ignored).
        $this->logInSession('unittest');
        $entry = $this->makeEntry('unittest');
        $id = $entry->getId();
        self::assertIsInt($id);

        $status = $this->deleteJson($id)->getStatusCode();

        self::assertSame(Response::HTTP_OK, $status);
        self::assertFalse($this->entryExists($id));
    }

    public function testMissingIdReturnsBadRequestNotSilentSuccess(): void
    {
        $this->logInSession('unittest');

        $this->client->request(
            Request::METHOD_POST,
            '/tracking/delete',
            [],
            [],
            ['CONTENT_TYPE' => self::JSON_MIME, 'HTTP_ACCEPT' => self::JSON_MIME],
            json_encode([], JSON_THROW_ON_ERROR),
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    public function testNonexistentIdReturnsNotFound(): void
    {
        $this->logInSession('unittest');

        self::assertSame(Response::HTTP_NOT_FOUND, $this->deleteJson(999999999)->getStatusCode());
    }

    public function testCannotDeleteAnotherUsersEntry(): void
    {
        // Entry owned by i.myself; developer (type DEV, not admin/PL) must not be
        // able to delete it — the ownership guard against IDOR.
        $entry = $this->makeEntry('i.myself');
        $id = $entry->getId();
        self::assertIsInt($id);

        $this->logInSession('developer');
        $status = $this->deleteJson($id)->getStatusCode();

        self::assertSame(Response::HTTP_FORBIDDEN, $status);
        self::assertTrue($this->entryExists($id));
    }

    public function testAdminCanDeleteAnotherUsersEntry(): void
    {
        // unittest is an admin, so it may delete an entry owned by another user.
        $entry = $this->makeEntry('developer');
        $id = $entry->getId();
        self::assertIsInt($id);

        $this->logInSession('unittest');
        $status = $this->deleteJson($id)->getStatusCode();

        self::assertSame(Response::HTTP_OK, $status);
        self::assertFalse($this->entryExists($id));
    }
}
