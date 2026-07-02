<?php

declare(strict_types=1);

namespace Tests\Controller;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Tests\AbstractWebTestCase;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;

/**
 * Functional tests for the holiday iCal import (POST /holiday/import-ical).
 *
 * The URL-fetch happy path needs a live feed and is not exercised here; the
 * scheme validation below fails BEFORE any network access, and the parser is
 * unit-tested separately.
 *
 * @internal
 */
final class ImportHolidaysActionTest extends AbstractWebTestCase
{
    public function testImportFromUploadedFileInsertsAndUpdates(): void
    {
        // First import: two new holidays.
        $this->client->request('POST', '/holiday/import-ical', [], ['file' => $this->icsUpload(
            "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20301224\r\nSUMMARY:Heiligabend\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20301231\r\nSUMMARY:Silvester\r\nEND:VEVENT",
        )]);
        $this->assertStatusCode(200);
        $first = $this->getJsonResponse($this->client->getResponse());
        self::assertSame(['success' => true, 'imported' => 2, 'updated' => 0, 'total' => 2], $first);

        // Second import: one existing day (renamed) + one new day.
        $this->client->request('POST', '/holiday/import-ical', [], ['file' => $this->icsUpload(
            "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20301224\r\nSUMMARY:Christmas Eve\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20301226\r\nSUMMARY:2. Weihnachtstag\r\nEND:VEVENT",
        )]);
        $this->assertStatusCode(200);
        $second = $this->getJsonResponse($this->client->getResponse());
        self::assertSame(['success' => true, 'imported' => 1, 'updated' => 1, 'total' => 2], $second);
    }

    public function testMissingPayloadIsRejected(): void
    {
        $this->client->request('POST', '/holiday/import-ical');
        $this->assertStatusCode(400);
    }

    public function testNonHttpUrlSchemeIsRejected(): void
    {
        $this->client->request('POST', '/holiday/import-ical', ['url' => 'file:///etc/passwd']);
        $this->assertStatusCode(400);
    }

    public function testUrlResolvingToPrivateIpIsRejected(): void
    {
        // SSRF guard: NoPrivateNetworkHttpClient blocks the request to a
        // loopback IP before any connection; the action maps that to 502.
        $this->client->request('POST', '/holiday/import-ical', ['url' => 'http://127.0.0.1/holidays.ics']);
        $this->assertStatusCode(502);
    }

    public function testFileWithoutEventsIsRejected(): void
    {
        $this->client->request('POST', '/holiday/import-ical', [], ['file' => $this->icsUpload('BEGIN:VCALENDAR END:VCALENDAR')]);
        $this->assertStatusCode(400);
    }

    public function testNonAdminIsRejected(): void
    {
        $this->logInSession('developer');
        $this->client->request('POST', '/holiday/import-ical', ['url' => 'https://example.com/holidays.ics'], [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(403);
    }

    private function icsUpload(string $content): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ics');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'holidays.ics', 'text/calendar', null, true);
    }
}
