<?php

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Exception\Integration\Jira\JiraApiException;
use App\Service\FrozenClock;
use App\Service\Integration\Jira\CloudOAuthStateCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Traits\TokenEncryptionTestTrait;

/**
 * Unit tests for the OAuth2 state codec of the Jira Cloud flow.
 *
 * @internal
 */
#[CoversClass(CloudOAuthStateCodec::class)]
final class CloudOAuthStateCodecTest extends TestCase
{
    use TokenEncryptionTestTrait;

    public function testEncodeDecodeRoundTrip(): void
    {
        $codec = $this->createCodec('2026-07-02 12:00:00');

        $state = $codec->encode(42, 7);
        $decoded = $codec->decode($state);

        self::assertSame(['userId' => 42, 'ticketSystemId' => 7], $decoded);
    }

    public function testStatesAreUniquePerCall(): void
    {
        $codec = $this->createCodec('2026-07-02 12:00:00');

        self::assertNotSame($codec->encode(1, 1), $codec->encode(1, 1));
    }

    public function testDecodeRejectsGarbage(): void
    {
        $codec = $this->createCodec('2026-07-02 12:00:00');

        try {
            $codec->decode('not-a-valid-state');
            self::fail('Expected JiraApiException');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('Invalid OAuth state', $exception->getMessage());
        }
    }

    public function testDecodeRejectsStateFromDifferentKey(): void
    {
        $codec = new CloudOAuthStateCodec(
            $this->createTokenEncryptionService('another-key'),
            new FrozenClock('2026-07-02 12:00:00'),
        );
        $foreignState = $codec->encode(42, 7);

        $this->expectException(JiraApiException::class);
        $this->createCodec('2026-07-02 12:00:00')->decode($foreignState);
    }

    public function testDecodeRejectsExpiredState(): void
    {
        $encodingCodec = $this->createCodec('2026-07-02 12:00:00');
        $state = $encodingCodec->encode(42, 7);

        // TTL is 600s — decode 11 minutes later.
        $decodingCodec = $this->createCodec('2026-07-02 12:11:00');

        try {
            $decodingCodec->decode($state);
            self::fail('Expected JiraApiException');
        } catch (JiraApiException $exception) {
            self::assertStringContainsString('expired', $exception->getMessage());
        }
    }

    public function testDecodeAcceptsStateWithinTtl(): void
    {
        $encodingCodec = $this->createCodec('2026-07-02 12:00:00');
        $state = $encodingCodec->encode(42, 7);

        $decodingCodec = $this->createCodec('2026-07-02 12:05:00');

        self::assertSame(['userId' => 42, 'ticketSystemId' => 7], $decodingCodec->decode($state));
    }

    private function createCodec(string $frozenTime): CloudOAuthStateCodec
    {
        return new CloudOAuthStateCodec(
            $this->createTokenEncryptionService(),
            new FrozenClock($frozenTime),
        );
    }
}
