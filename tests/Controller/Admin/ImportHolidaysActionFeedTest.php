<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Admin;

use App\Controller\Admin\ImportHolidaysAction;
use App\Service\Util\IcalHolidayParser;
use Doctrine\Persistence\ManagerRegistry;
use Generator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag as ContainerParameterBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function file_put_contents;
use function json_decode;
use function str_repeat;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const JSON_THROW_ON_ERROR;
use const UPLOAD_ERR_PARTIAL;

/**
 * Pins how ImportHolidaysAction reads its iCal payload (upload or feed URL): the size caps,
 * the empty and unreadable cases and the URL checks. Payloads that pass reading end in the
 * "no events" rejection, which needs no database.
 *
 * @internal
 */
#[CoversClass(ImportHolidaysAction::class)]
#[AllowMockObjectsWithoutExpectations]
final class ImportHolidaysActionFeedTest extends TestCase
{
    private const int MAX_ICAL_BYTES = 1_048_576;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            @unlink($tempFile);
        }
    }

    public function testUploadWithTransferErrorIsUnreadable(): void
    {
        $upload = new UploadedFile($this->tempFile(''), 'holidays.ics', null, UPLOAD_ERR_PARTIAL, true);

        self::assertSame([400, 'The uploaded file could not be read.'], $this->importUpload($upload));
    }

    public function testOversizedUploadIsRejectedByReportedSize(): void
    {
        $upload = new UploadedFile($this->tempFile(str_repeat('a', self::MAX_ICAL_BYTES + 1)), 'holidays.ics', null, null, true);

        self::assertSame([400, 'The iCal data is too large.'], $this->importUpload($upload));
    }

    public function testEmptyUploadIsUnreadable(): void
    {
        $upload = new UploadedFile($this->tempFile(''), 'holidays.ics', null, null, true);

        self::assertSame([400, 'The uploaded file could not be read.'], $this->importUpload($upload));
    }

    public function testUploadAtTheCapIsAccepted(): void
    {
        $upload = new UploadedFile($this->tempFile(str_repeat('a', self::MAX_ICAL_BYTES)), 'holidays.ics', null, null, true);

        self::assertSame([400, 'No events found in iCal data.'], $this->importUpload($upload));
    }

    public function testMissingPayloadAsksForUrlOrFile(): void
    {
        self::assertSame([400, 'Provide an iCal URL or upload an .ics file.'], $this->importRequest(new Request()));
        self::assertSame([400, 'Provide an iCal URL or upload an .ics file.'], $this->importRequest(new Request([], ['url' => ''])));
    }

    public function testNonHttpSchemeIsRejected(): void
    {
        self::assertSame([400, 'Only http(s) iCal URLs are supported.'], $this->importUrl('file:///etc/passwd'));
    }

    public function testAdvertisedContentLengthOverTheCapIsRejectedAndCancelled(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaders')->willReturn(['content-length' => [(string) (self::MAX_ICAL_BYTES + 1)]]);
        $response->expects(self::once())->method('cancel');

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $client->expects(self::never())->method('stream');

        self::assertSame([400, 'The iCal data is too large.'], $this->importUrl('https://example.com/h.ics', $client));
    }

    public function testStreamedBodyOverTheCapIsRejectedAndCancelled(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaders')->willReturn([]);
        $response->expects(self::once())->method('cancel');

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $client->method('stream')->willReturn(new ResponseStream((static function () use ($response): Generator {
            yield $response => self::chunk(str_repeat('a', self::MAX_ICAL_BYTES));
            yield $response => self::chunk('a');
        })()));

        self::assertSame([400, 'The iCal data is too large.'], $this->importUrl('https://example.com/h.ics', $client));
    }

    public function testStreamedBodyAtTheCapIsAccepted(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getHeaders')->willReturn([]);
        $response->expects(self::never())->method('cancel');

        $client = $this->createMock(HttpClientInterface::class);
        $client->method('request')->willReturn($response);
        $client->method('stream')->willReturn(new ResponseStream((static function () use ($response): Generator {
            yield $response => self::chunk(str_repeat('a', self::MAX_ICAL_BYTES));
        })()));

        self::assertSame([400, 'No events found in iCal data.'], $this->importUrl('https://example.com/h.ics', $client));
    }

    public function testEmptyFeedIsRejected(): void
    {
        self::assertSame([400, 'The iCal feed is empty.'], $this->importUrl('https://example.com/h.ics', new MockHttpClient(new MockResponse(''))));
    }

    public function testTransportFailureIsReportedAsBadGatewayAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Holiday iCal fetch failed', self::anything());

        $response = new MockResponse('', ['error' => 'connection refused']);

        self::assertSame([502, 'The iCal feed could not be fetched.'], $this->importUrl('https://example.com/h.ics', new MockHttpClient($response), $logger));
    }

    public function testReadableFeedReachesTheParser(): void
    {
        self::assertSame([400, 'No events found in iCal data.'], $this->importUrl('https://example.com/h.ics', new MockHttpClient(new MockResponse("BEGIN:VCALENDAR\nEND:VCALENDAR"))));
    }

    /**
     * @return array{int, string}
     */
    private function importUpload(UploadedFile $upload): array
    {
        return $this->importRequest(new Request([], [], [], [], ['file' => $upload]));
    }

    /**
     * @return array{int, string}
     */
    private function importUrl(string $url, ?HttpClientInterface $client = null, ?LoggerInterface $logger = null): array
    {
        return $this->importRequest(new Request([], ['url' => $url]), $client, $logger);
    }

    /**
     * @return array{int, string}
     */
    private function importRequest(Request $request, ?HttpClientInterface $client = null, ?LoggerInterface $logger = null): array
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $action = new ImportHolidaysAction();
        $action->setCoreDependencies(
            $this->createMock(ManagerRegistry::class),
            new ContainerParameterBag(),
            $translator,
            $this->createMock(KernelInterface::class),
        );
        $action->setImportDependencies(
            new MockHttpClient(new MockResponse('')),
            new IcalHolidayParser(),
            $logger ?? $this->createMock(LoggerInterface::class),
        );

        // setImportDependencies() wraps the client in the SSRF guard, which resolves real IPs;
        // the feed handling under test sits behind it, so hand the action the mock directly.
        if ($client instanceof HttpClientInterface) {
            new ReflectionProperty($action, 'httpClient')->setValue($action, $client);
        }

        $result = $action($request);
        $payload = json_decode((string) $result->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['message']);

        return [$result->getStatusCode(), $payload['message']];
    }

    private static function chunk(string $content): ChunkInterface
    {
        $chunk = self::createStub(ChunkInterface::class);
        $chunk->method('getContent')->willReturn($content);

        return $chunk;
    }

    private function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ics');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
