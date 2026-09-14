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
use App\Service\Tracking\DayClassService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AbstractWebTestCase;
use Tests\Traits\EntityManagerTestTrait;

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
    use EntityManagerTestTrait;

    private const string JSON_MIME = 'application/json';

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

    public function testDeletingOneHalfOfAPairDeletesBoth(): void
    {
        // ADR-025: the agent walltime entry and its delegated human estimate are one
        // logged session; deleting either half must not leave the other orphaned.
        $this->logInSession('unittest');
        $agent = $this->makeEntry('unittest');
        $human = $this->makeEntry('unittest');
        $agent->pairWith($human);
        $this->entityManager()->flush();
        $agentId = $agent->getId();
        $humanId = $human->getId();
        self::assertIsInt($agentId);
        self::assertIsInt($humanId);

        $response = $this->deleteJson($humanId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertEqualsCanonicalizing([$humanId, $agentId], $body['deleted'] ?? null);
        $this->entityManager()->clear();
        self::assertFalse($this->entryExists($humanId));
        self::assertFalse($this->entryExists($agentId));
    }

    public function testRemovingOneHalfOutsideTheActionUnlinksThePartner(): void
    {
        // Other delete paths (worklog sync, conflict resolution) remove a single entry
        // through the EntityManager while its partner may still be managed. That must
        // not fail on the stale back-reference; the survivor simply loses its link.
        $agent = $this->makeEntry('unittest');
        $human = $this->makeEntry('unittest');
        $agent->pairWith($human);
        $entityManager = $this->entityManager();
        $entityManager->flush();
        $agentId = $agent->getId();
        $humanId = $human->getId();
        self::assertIsInt($agentId);
        self::assertIsInt($humanId);

        $entityManager->remove($human);
        $entityManager->flush();
        // Like the sync services: recalculate the day in the SAME unit of work, which
        // reloads the surviving partner and flushes again while it still holds the
        // in-memory reference to the removed half.
        self::getContainer()->get(DayClassService::class)->recalculate(1, '2024-01-15');
        $entityManager->flush();
        $entityManager->clear();

        self::assertFalse($this->entryExists($humanId));
        $survivor = $entityManager->getRepository(Entry::class)->find($agentId);
        self::assertInstanceOf(Entry::class, $survivor);
        self::assertNull($survivor->getPairedEntry());
    }

    public function testDeletingAnUnpairedEntryLeavesOthersAlone(): void
    {
        $this->logInSession('unittest');
        $target = $this->makeEntry('unittest');
        $bystander = $this->makeEntry('unittest');
        $targetId = $target->getId();
        $bystanderId = $bystander->getId();
        self::assertIsInt($targetId);
        self::assertIsInt($bystanderId);

        $response = $this->deleteJson($targetId);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame([$targetId], $body['deleted'] ?? null);
        $this->entityManager()->clear();
        self::assertTrue($this->entryExists($bystanderId));
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
