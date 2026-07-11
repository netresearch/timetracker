<?php

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\EntrySource;
use PHPUnit\Framework\TestCase;

final class EntrySourceTest extends TestCase
{
    public function testCasesAndLabels(): void
    {
        self::assertSame('human', EntrySource::HUMAN->value);
        self::assertSame('agent', EntrySource::AGENT->value);
        self::assertSame('Human', EntrySource::HUMAN->label());
    }

    public function testValidRejectsUnknown(): void
    {
        self::assertTrue(EntrySource::Valid('agent'));
        self::assertFalse(EntrySource::Valid('robot'));
    }
}
