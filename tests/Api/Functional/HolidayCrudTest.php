<?php

declare(strict_types=1);

namespace Tests\Api\Functional;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_array;

/**
 * API Functional Tests - Holiday CRUD Operations (real database).
 *
 * Holidays are immutable and keyed by day: create + delete only, no update.
 *
 * @internal
 *
 * @coversNothing
 */
final class HolidayCrudTest extends AbstractWebTestCase
{
    public function testCreateListDeleteHoliday(): void
    {
        $this->logInSession('unittest');
        $day = '2099-01-02';

        // Best-effort cleanup so the date-keyed create starts clean (idempotent re-runs).
        $this->createJsonRequest(Request::METHOD_POST, '/holiday/delete', ['day' => $day]);

        // Create
        $this->createJsonRequest(Request::METHOD_POST, '/holiday/save', ['day' => $day, 'name' => 'Synthetic Holiday']);
        $this->assertStatusCode(200);
        $created = $this->getJsonResponse($this->client->getResponse());
        self::assertSame($day, $created['day']);
        self::assertSame('Synthetic Holiday', $created['name']);

        // List — row-wrapped {holiday:{id, day, name}} with the synthetic Ymd id.
        $this->createJsonRequest(Request::METHOD_GET, '/getAllHolidays');
        $this->assertStatusCode(200);
        $list = $this->getJsonResponse($this->client->getResponse());
        $match = null;
        foreach ($list as $item) {
            /** @var array<string, mixed> $item */
            $holiday = isset($item['holiday']) && is_array($item['holiday']) ? $item['holiday'] : $item;
            if (($holiday['day'] ?? null) === $day) {
                $match = $holiday;
            }
        }

        self::assertNotNull($match, 'the created holiday appears in the list');
        self::assertSame(20990102, $match['id']);
        self::assertSame('Synthetic Holiday', $match['name']);

        // Delete by day (holidays have no numeric id).
        $this->createJsonRequest(Request::METHOD_POST, '/holiday/delete', ['day' => $day]);
        $this->assertStatusCode(200);
        self::assertTrue($this->getJsonResponse($this->client->getResponse())['success']);
    }

    public function testDuplicateDayIsRejectedAsConflict(): void
    {
        $this->logInSession('unittest');
        $day = '2099-01-03';
        $this->createJsonRequest(Request::METHOD_POST, '/holiday/delete', ['day' => $day]);

        $this->createJsonRequest(Request::METHOD_POST, '/holiday/save', ['day' => $day, 'name' => 'First']);
        $this->assertStatusCode(200);

        // A holiday is keyed by day, so re-saving the same day is a conflict, not an update.
        $this->createJsonRequest(Request::METHOD_POST, '/holiday/save', ['day' => $day, 'name' => 'Second']);
        $this->assertStatusCode(409);

        $this->createJsonRequest(Request::METHOD_POST, '/holiday/delete', ['day' => $day]);
    }

    public function testInvalidDateIsRejected(): void
    {
        $this->logInSession('unittest');

        $this->createJsonRequest(Request::METHOD_POST, '/holiday/save', ['day' => 'not-a-date', 'name' => 'Bad']);
        $this->assertStatusCode(422);
    }

    public function testDeleteUnknownHolidayReturnsError(): void
    {
        $this->logInSession('unittest');

        $this->createJsonRequest(Request::METHOD_POST, '/holiday/delete', ['day' => '2099-12-31']);
        $this->assertStatusCode(422);
    }
}
