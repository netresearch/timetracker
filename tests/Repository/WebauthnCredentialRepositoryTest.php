<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Repository;

use App\Entity\WebauthnCredential;
use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Tests\AbstractWebTestCase;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

use function assert;
use function random_bytes;

/**
 * Covers the passkey persistence path (ADR-018 D3). The registration ceremony
 * hands saveCredentialRecord() the BASE CredentialRecord (constructed by the
 * attestation validator, no entity id) — the repository must convert it into the
 * id-bearing entity, or Doctrine refuses it ("entity has no identity"; the bug
 * that broke passkey registration in production).
 *
 * @internal
 *
 * @coversNothing
 */
final class WebauthnCredentialRepositoryTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    private WebauthnCredentialRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        if (null === $this->serviceContainer) {
            throw new RuntimeException('Service container not initialized');
        }
        $entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
        $repository = $this->entityManager->getRepository(WebauthnCredential::class);
        assert($repository instanceof WebauthnCredentialRepository);
        $this->repository = $repository;
    }

    public function testSavingABaseCredentialRecordPersistsItAsTheEntity(): void
    {
        $credentialId = random_bytes(32);
        $userHandle = Uuid::v4()->toRfc4122();

        // What the attestation validator produces on registration: the BASE class.
        $record = new CredentialRecord(
            $credentialId,
            'public-key',
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::v4(),
            'test-public-key',
            $userHandle,
            0,
        );

        $this->repository->saveCredentialRecord($record);

        // Round-trip from the DB: it must come back as the id-bearing entity.
        $this->entityManager->clear();
        $found = $this->repository->findOneByCredentialId($credentialId);
        self::assertInstanceOf(WebauthnCredential::class, $found);
        self::assertNotNull($found->getId());
        self::assertSame($userHandle, $found->userHandle);
        self::assertSame(1, $this->repository->countByUserHandle($userHandle));
    }

    public function testSavingTheManagedEntityAgainUpdatesInPlace(): void
    {
        $credentialId = random_bytes(32);
        $userHandle = Uuid::v4()->toRfc4122();

        $this->repository->saveCredentialRecord(new CredentialRecord(
            $credentialId,
            'public-key',
            ['internal'],
            'none',
            EmptyTrustPath::create(),
            Uuid::v4(),
            'test-public-key',
            $userHandle,
            0,
        ));

        // The login path: load the entity, bump the sign counter, save it back.
        $this->entityManager->clear();
        $entity = $this->repository->findOneByCredentialId($credentialId);
        self::assertInstanceOf(WebauthnCredential::class, $entity);
        $entity->counter = 7;
        $this->repository->saveCredentialRecord($entity);

        $this->entityManager->clear();
        $reloaded = $this->repository->findOneByCredentialId($credentialId);
        self::assertInstanceOf(WebauthnCredential::class, $reloaded);
        self::assertSame(7, $reloaded->counter, 'the counter update is persisted');
        self::assertSame(1, $this->repository->countByUserHandle($userHandle), 'no duplicate row was created');
    }
}
