<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WebauthnCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Webauthn\Bundle\Repository\CanSaveCredentialRecord;
use Webauthn\Bundle\Repository\CredentialRecordRepositoryInterface;
use Webauthn\Bundle\Repository\PublicKeyCredentialSourceRepositoryInterface;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialUserEntity;

use function base64_encode;

/**
 * Persists registered passkeys (ADR-018 D3). Implements the bundle's v5 record
 * interfaces (not the deprecated PublicKeyCredentialSource ones); the ceremony
 * controllers read via {@see findAllForUserEntity}/{@see findOneByCredentialId}
 * and write via {@see saveCredentialRecord}. Mirrors the query shape of the
 * bundle's own DoctrineCredentialSourceRepository (userHandle match; base64 id).
 *
 * @extends ServiceEntityRepository<WebauthnCredential>
 *
 * @phpstan-ignore class.implementsDeprecatedInterface (the bundle's DI aliases the
 *   deprecated marker to the configured repo; implementing it is required until 6.0)
 */
class WebauthnCredentialRepository extends ServiceEntityRepository implements PublicKeyCredentialSourceRepositoryInterface, CanSaveCredentialRecord
{
    /** DQL predicate shared by every by-user-handle query below. */
    private const string USER_HANDLE_PREDICATE = 'c.userHandle = :userHandle';

    // PublicKeyCredentialSourceRepositoryInterface is an (deprecated) empty marker
    // extending CredentialRecordRepositoryInterface — the bundle aliases both to
    // the configured repo, so we implement the marker to satisfy the DI alias
    // while defining only the current CredentialRecord-based methods below.
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebauthnCredential::class);
    }

    public function saveCredentialRecord(CredentialRecord $credentialRecord): void
    {
        // Registration hands us the BASE CredentialRecord (the attestation
        // validator constructs it) — convert it into the id-bearing entity
        // subclass, or Doctrine cannot persist it ("entity has no identity").
        // Login counter-updates pass back the managed entity from
        // findOneByCredentialId and are persisted as-is.
        if (!$credentialRecord instanceof WebauthnCredential) {
            $credentialRecord = new WebauthnCredential(
                $credentialRecord->publicKeyCredentialId,
                $credentialRecord->type,
                $credentialRecord->transports,
                $credentialRecord->attestationType,
                $credentialRecord->trustPath,
                $credentialRecord->aaguid,
                $credentialRecord->credentialPublicKey,
                $credentialRecord->userHandle,
                $credentialRecord->counter,
                $credentialRecord->otherUI,
                $credentialRecord->backupEligible,
                $credentialRecord->backupStatus,
                $credentialRecord->uvInitialized,
            );
        }

        $manager = $this->getEntityManager();
        $manager->persist($credentialRecord);
        $manager->flush();
    }

    /**
     * @return array<CredentialRecord>
     */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        /** @var array<CredentialRecord> $records */
        $records = $this->getEntityManager()
            ->createQueryBuilder()
            ->from(WebauthnCredential::class, 'c')
            ->select('c')
            ->where(self::USER_HANDLE_PREDICATE)
            ->setParameter('userHandle', $publicKeyCredentialUserEntity->id)
            ->getQuery()
            ->getResult();

        return $records;
    }

    /**
     * The app's own credential rows for a user handle (with their surrogate ids),
     * for the Settings passkey list/remove UI — distinct from the bundle's
     * findAllForUserEntity, which returns the abstract CredentialRecord.
     *
     * @return array<WebauthnCredential>
     */
    public function findByUserHandle(string $userHandle): array
    {
        // A DQL query (not findBy) so PHPStan's Doctrine plugin doesn't trip over
        // userHandle being mapped on the XML mapped-superclass, not this subclass.
        /** @var array<WebauthnCredential> $records */
        $records = $this->getEntityManager()
            ->createQueryBuilder()
            ->from(WebauthnCredential::class, 'c')
            ->select('c')
            ->where(self::USER_HANDLE_PREDICATE)
            ->setParameter('userHandle', $userHandle)
            ->getQuery()
            ->getResult();

        return $records;
    }

    /**
     * How many passkeys a user handle has registered — for the mandatory-2FA
     * gate, which counts a passkey as a valid second factor (ADR-018).
     */
    public function countByUserHandle(string $userHandle): int
    {
        $count = $this->getEntityManager()
            ->createQueryBuilder()
            ->from(WebauthnCredential::class, 'c')
            ->select('COUNT(c.id)')
            ->where(self::USER_HANDLE_PREDICATE)
            ->setParameter('userHandle', $userHandle)
            ->getQuery()
            ->getSingleScalarResult();

        // getSingleScalarResult() is mixed (COUNT comes back as a numeric string
        // on some drivers) — normalise to int.
        return (int) $count;
    }

    public function findOneByCredentialId(string $publicKeyCredentialId): ?CredentialRecord
    {
        /** @var CredentialRecord|null $record */
        $record = $this->getEntityManager()
            ->createQueryBuilder()
            ->from(WebauthnCredential::class, 'c')
            ->select('c')
            ->where('c.publicKeyCredentialId = :publicKeyCredentialId')
            ->setParameter('publicKeyCredentialId', base64_encode($publicKeyCredentialId))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $record;
    }

    /**
     * Passkey counts for EVERY user handle in one grouped query — feeds the
     * admin users grid without a per-user COUNT (N+1).
     *
     * @return array<string, int> userHandle => number of passkeys
     */
    public function countsByUserHandle(): array
    {
        /** @var list<array{userHandle: string, n: int|string}> $rows */
        $rows = $this->getEntityManager()
            ->createQueryBuilder()
            ->from(WebauthnCredential::class, 'c')
            ->select('c.userHandle AS userHandle', 'COUNT(c.id) AS n')
            ->groupBy('c.userHandle')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            // Defensive: the ceremony always sets a handle, but a null/empty one
            // must not collapse several users onto the '' key.
            if ('' === $row['userHandle']) {
                continue;
            }

            $counts[$row['userHandle']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Remove every passkey of a user handle — the admin break-glass reset
     * (ADR-018): the account falls back to password-only sign-in.
     */
    public function deleteByUserHandle(string $userHandle): void
    {
        $this->getEntityManager()
            ->createQueryBuilder()
            ->delete(WebauthnCredential::class, 'c')
            ->where(self::USER_HANDLE_PREDICATE)
            ->setParameter('userHandle', $userHandle)
            ->getQuery()
            ->execute();
    }
}
