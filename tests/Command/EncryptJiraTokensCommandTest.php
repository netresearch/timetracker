<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Command;

use App\Command\EncryptJiraTokensCommand;
use App\Entity\UserTicketsystem;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Traits\TokenEncryptionTestTrait;

/**
 * @internal
 *
 * @coversNothing
 */
#[AllowMockObjectsWithoutExpectations]
final class EncryptJiraTokensCommandTest extends KernelTestCase
{
    use TokenEncryptionTestTrait;

    protected static function ensureKernelShutdown(): void
    {
        $wasBooted = self::$booted;
        parent::ensureKernelShutdown();
        if ($wasBooted) {
            @restore_exception_handler();
        }
    }

    public function testEncryptsOnlyPlaintextTokensAndIsIdempotent(): void
    {
        self::bootKernel();

        $encryption = $this->createTokenEncryptionService();

        // A legacy row stored before encryption-at-rest: plaintext.
        $plaintext = new UserTicketsystem();
        $plaintext->setTokenSecret('plain-secret')->setAccessToken('plain-token');

        // A row already encrypted (e.g. written/refreshed after the change).
        $alreadyEncrypted = new UserTicketsystem();
        $alreadyEncrypted->setTokenSecret($encryption->encryptToken('enc-secret'))
            ->setAccessToken($encryption->encryptToken('enc-token'));
        $encryptedSecretBefore = $alreadyEncrypted->getTokenSecret();

        // A disconnected row (avoid-connection): empty, must stay untouched.
        $empty = new UserTicketsystem();
        $empty->setTokenSecret('')->setAccessToken('');

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findAll'])
            ->getMock();
        $repository->method('findAll')->willReturn([$plaintext, $alreadyEncrypted, $empty]);

        /** @var EntityManagerInterface&MockObject $entityManager */
        $entityManager = $this->getMockBuilder(EntityManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('flush');

        $command = new EncryptJiraTokensCommand($entityManager, $encryption);
        $application = new Application();
        $application->addCommand($command);

        $commandTester = new CommandTester($application->find('tt:encrypt-jira-tokens'));
        $exitCode = $commandTester->execute([]);

        self::assertSame(0, $exitCode);

        // The plaintext row is now encrypted and decrypts back to the originals.
        self::assertNotSame('plain-secret', $plaintext->getTokenSecret());
        self::assertSame('plain-secret', $encryption->decryptToken($plaintext->getTokenSecret()));
        self::assertSame('plain-token', $encryption->decryptToken($plaintext->getAccessToken()));

        // The already-encrypted row is left byte-for-byte unchanged (idempotent).
        self::assertSame($encryptedSecretBefore, $alreadyEncrypted->getTokenSecret());

        // The empty row is untouched.
        self::assertSame('', $empty->getTokenSecret());
        self::assertSame('', $empty->getAccessToken());
    }
}
