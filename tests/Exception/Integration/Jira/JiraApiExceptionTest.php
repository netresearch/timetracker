<?php

declare(strict_types=1);

namespace Tests\Exception\Integration\Jira;

use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for JiraApiException and its subclasses.
 *
 * @internal
 */
#[CoversClass(JiraApiException::class)]
#[CoversClass(JiraApiInvalidResourceException::class)]
#[CoversClass(JiraApiUnauthorizedException::class)]
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

    // ==================== JiraApiInvalidResourceException tests ====================

    public function testInvalidResourceExceptionPrefixesMessage(): void
    {
        $exception = new JiraApiInvalidResourceException('Resource not found');

        self::assertSame('Jira: Resource not found', $exception->getMessage());
    }

    public function testInvalidResourceExceptionWithCode(): void
    {
        $exception = new JiraApiInvalidResourceException('Issue PROJ-123 not found', 404);

        self::assertSame(404, $exception->getCode());
    }

    public function testInvalidResourceExceptionInheritsFromJiraApiException(): void
    {
        // Verify inheritance via type hint - this will fail to compile if inheritance is broken
        $this->expectNotToPerformAssertions();
        $exception = new JiraApiInvalidResourceException('Test');
        // Type hint verifies inheritance
        $this->acceptJiraApiException($exception);
    }

    // ==================== JiraApiUnauthorizedException tests ====================

    public function testUnauthorizedExceptionPrefixesMessage(): void
    {
        $exception = new JiraApiUnauthorizedException('Please authorize');

        self::assertSame('Jira: Please authorize', $exception->getMessage());
    }

    public function testUnauthorizedExceptionWithRedirectUrl(): void
    {
        $exception = new JiraApiUnauthorizedException(
            'Authorization required',
            401,
            'https://jira.example.com/oauth/authorize',
        );

        self::assertSame('https://jira.example.com/oauth/authorize', $exception->getRedirectUrl());
        self::assertSame(401, $exception->getCode());
    }

    public function testUnauthorizedExceptionInheritsFromJiraApiException(): void
    {
        // Verify inheritance via type hint - this will fail to compile if inheritance is broken
        $this->expectNotToPerformAssertions();
        $exception = new JiraApiUnauthorizedException('Test');
        // Type hint verifies inheritance
        $this->acceptJiraApiException($exception);
    }

    /**
     * Helper to verify inheritance via type hint.
     */
    private function acceptJiraApiException(JiraApiException $exception): void
    {
        // Type hint ensures the exception is a JiraApiException
    }
}
