<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\Service\Sync\WorklogCommentCodec;
use PHPUnit\Framework\TestCase;

final class WorklogCommentCodecTest extends TestCase
{
    private WorklogCommentCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new WorklogCommentCodec();
    }

    public function testEncodeMatchesProductionPushFormat(): void
    {
        self::assertSame('#42: Development: fixed the bug', $this->codec->encode(42, 'Development', 'fixed the bug'));
    }

    public function testEncodeFallbacksMatchLegacyService(): void
    {
        self::assertSame('#42: no activity specified: no description given', $this->codec->encode(42, null, ''));
        self::assertSame('#42: Development: no description given', $this->codec->encode(42, 'Development', '0'));
    }

    public function testNormalizeTrimsAndUnifiesLineEndings(): void
    {
        self::assertSame("a\nb", WorklogCommentCodec::normalize("  a\r\nb \n"));
    }

    public function testDecodeStripsTtPrefix(): void
    {
        self::assertSame('fixed the bug', WorklogCommentCodec::decode('#42: Development: fixed the bug'));
    }

    public function testDecodeKeepsColonsInsideDescription(): void
    {
        self::assertSame('note: see FOO-1', WorklogCommentCodec::decode('#42: Development: note: see FOO-1'));
    }

    public function testDecodePassesThroughPlainJiraComments(): void
    {
        self::assertSame('plain jira comment', WorklogCommentCodec::decode('plain jira comment'));
        self::assertSame('#123 not our format', WorklogCommentCodec::decode('#123 not our format'));
    }

    public function testDecodeNormalizes(): void
    {
        self::assertSame("a\nb", WorklogCommentCodec::decode("  #42: Dev: a\r\nb "));
    }
}
