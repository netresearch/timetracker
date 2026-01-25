<?php

declare(strict_types=1);

namespace Tests\Exception\Integration\Jira;

use App\Exception\Integration\Jira\JiraApiException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for JiraApiException.
 *
 * @internal
 */
#[CoversClass(JiraApiException::class)]
final class JiraApiExceptionTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorPrefixesMessageWithJira(): void
    {
        $exception = new JiraApiException('Connection failed');

        self::assertSame('Jira: Connection failed', $exception->getMessage());
    }

    public function testConstructorDoesNotDuplicateJiraPrefix(): void
    {
        $exception = new JiraApiException('Jira: Connection failed');

        self::assertSame('Jira: Connection failed', $exception->getMessage());
    }

    public function testConstructorSetsCode(): void
    {
        $exception = new JiraApiException('Error', 500);

        self::assertSame(500, $exception->getCode());
    }

    public function testConstructorSetsDefaultCodeToZero(): void
    {
        $exception = new JiraApiException('Error');

        self::assertSame(0, $exception->getCode());
    }

    public function testConstructorSetsPreviousException(): void
    {
        $previous = new RuntimeException('Previous error');
        $exception = new JiraApiException('Error', 0, null, $previous);

        self::assertSame($previous, $exception->getPrevious());
    }

    // ==================== getRedirectUrl tests ====================

    public function testGetRedirectUrlReturnsNullByDefault(): void
    {
        $exception = new JiraApiException('Error');

        self::assertNull($exception->getRedirectUrl());
    }

    public function testGetRedirectUrlReturnsSetValue(): void
    {
        $exception = new JiraApiException('Error', 0, '/oauth/authorize');

        self::assertSame('/oauth/authorize', $exception->getRedirectUrl());
    }

    public function testGetRedirectUrlWithFullUrl(): void
    {
        $exception = new JiraApiException(
            'Authentication required',
            401,
            'https://jira.example.com/plugins/servlet/oauth/authorize?oauth_token=abc123',
        );

        self::assertSame(
            'https://jira.example.com/plugins/servlet/oauth/authorize?oauth_token=abc123',
            $exception->getRedirectUrl(),
        );
    }
}
