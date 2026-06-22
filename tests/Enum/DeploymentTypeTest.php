<?php

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\DeploymentType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeploymentType enum.
 *
 * @internal
 */
#[CoversClass(DeploymentType::class)]
final class DeploymentTypeTest extends TestCase
{
    public function testServerHasCorrectValue(): void
    {
        self::assertSame('SERVER', DeploymentType::SERVER->value);
    }

    public function testCloudHasCorrectValue(): void
    {
        self::assertSame('CLOUD', DeploymentType::CLOUD->value);
    }

    public function testGetDisplayNameForServer(): void
    {
        self::assertSame('Server / Data Center', DeploymentType::SERVER->getDisplayName());
    }

    public function testGetDisplayNameForCloud(): void
    {
        self::assertSame('Cloud', DeploymentType::CLOUD->getDisplayName());
    }

    public function testAllReturnsAllCases(): void
    {
        $all = DeploymentType::all();

        self::assertCount(2, $all);
        self::assertContains(DeploymentType::SERVER, $all);
        self::assertContains(DeploymentType::CLOUD, $all);
    }

    public function testCanCreateFromString(): void
    {
        self::assertSame(DeploymentType::SERVER, DeploymentType::from('SERVER'));
        self::assertSame(DeploymentType::CLOUD, DeploymentType::from('CLOUD'));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(DeploymentType::tryFrom('INVALID'));
        self::assertNull(DeploymentType::tryFrom('server')); // lowercase
    }
}
