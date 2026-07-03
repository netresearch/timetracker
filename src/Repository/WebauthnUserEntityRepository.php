<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\PublicKeyCredentialUserEntity;

use function assert;

/**
 * Maps the app's {@see User} onto the WebAuthn user-entity the ceremonies need
 * (ADR-018 D3). The credential is bound to the user's non-enumerable
 * webauthn_user_handle, which is lazily minted (a random UUID) the first time a
 * user is looked up by name for a registration ceremony — so an account only
 * gains a handle once it actually starts using passkeys.
 */
final readonly class WebauthnUserEntityRepository implements PublicKeyCredentialUserEntityRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if (!$user instanceof User) {
            return null;
        }

        if (null === $user->getWebauthnUserHandle()) {
            // Assign a stable handle exactly once, ATOMICALLY: the guarded UPDATE
            // wins for at most one concurrent request (the rest touch zero rows),
            // so there is no lost-update race, the unique index forbids duplicates,
            // and — unlike a read-modify-write flush — an existing handle can never
            // be overwritten (matters because the login-options builder can reach
            // this method with a request-supplied username).
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE users SET webauthn_user_handle = :handle WHERE id = :id AND webauthn_user_handle IS NULL',
                ['handle' => Uuid::v4()->toRfc4122(), 'id' => $user->getId()],
            );
            $this->entityManager->refresh($user);
        }

        return $this->toUserEntity($user);
    }

    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['webauthnUserHandle' => $userHandle]);

        return $user instanceof User ? $this->toUserEntity($user) : null;
    }

    private function toUserEntity(User $user): PublicKeyCredentialUserEntity
    {
        $handle = $user->getWebauthnUserHandle();
        assert(null !== $handle);

        return PublicKeyCredentialUserEntity::create(
            $user->getUserIdentifier(),
            $handle,
            $user->getAbbr() ?? $user->getUserIdentifier(),
        );
    }
}
