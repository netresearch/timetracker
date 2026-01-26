<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\TicketSystemSaveDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TicketSystemSaveDto.
 *
 * @internal
 */
#[CoversClass(TicketSystemSaveDto::class)]
final class TicketSystemSaveDtoTest extends TestCase
{
    public function testConstructorWithDefaultValues(): void
    {
        $dto = new TicketSystemSaveDto();

        self::assertNull($dto->id);
        self::assertSame('', $dto->name);
        self::assertSame('', $dto->type);
        self::assertFalse($dto->bookTime);
        self::assertSame('', $dto->url);
        self::assertSame('', $dto->login);
        self::assertSame('', $dto->password);
        self::assertSame('', $dto->publicKey);
        self::assertSame('', $dto->privateKey);
        self::assertSame('', $dto->ticketUrl);
        self::assertNull($dto->oauthConsumerKey);
        self::assertNull($dto->oauthConsumerSecret);
    }

    public function testConstructorWithCustomValues(): void
    {
        $dto = new TicketSystemSaveDto(
            id: 42,
            name: 'JIRA Production',
            type: 'jira',
            bookTime: true,
            url: 'https://jira.example.com',
            login: 'api-user',
            password: 'secret123',
            publicKey: 'pub-key-content',
            privateKey: 'priv-key-content',
            ticketUrl: 'https://jira.example.com/browse/%s',
            oauthConsumerKey: 'consumer-key',
            oauthConsumerSecret: 'consumer-secret',
        );

        self::assertSame(42, $dto->id);
        self::assertSame('JIRA Production', $dto->name);
        self::assertSame('jira', $dto->type);
        self::assertTrue($dto->bookTime);
        self::assertSame('https://jira.example.com', $dto->url);
        self::assertSame('api-user', $dto->login);
        self::assertSame('secret123', $dto->password);
        self::assertSame('pub-key-content', $dto->publicKey);
        self::assertSame('priv-key-content', $dto->privateKey);
        self::assertSame('https://jira.example.com/browse/%s', $dto->ticketUrl);
        self::assertSame('consumer-key', $dto->oauthConsumerKey);
        self::assertSame('consumer-secret', $dto->oauthConsumerSecret);
    }

    public function testConstructorWithPartialValues(): void
    {
        $dto = new TicketSystemSaveDto(
            name: 'Test System',
            type: 'github',
            url: 'https://github.com',
        );

        self::assertNull($dto->id);
        self::assertSame('Test System', $dto->name);
        self::assertSame('github', $dto->type);
        self::assertFalse($dto->bookTime);
        self::assertSame('https://github.com', $dto->url);
        self::assertSame('', $dto->login);
        self::assertSame('', $dto->password);
        self::assertNull($dto->oauthConsumerKey);
        self::assertNull($dto->oauthConsumerSecret);
    }
}
