<?php

declare(strict_types=1);

namespace Tests\Helper;

use App\Helper\LocalizationHelper;
use PHPUnit\Framework\TestCase;

class LocalizationHelperTest extends TestCase
{
    public function testNormalizeLocaleDelegatesToService(): void
    {
        $this->assertSame('en', LocalizationHelper::normalizeLocale('en-US'));
        $this->assertSame('de', LocalizationHelper::normalizeLocale('de'));
    }
}


