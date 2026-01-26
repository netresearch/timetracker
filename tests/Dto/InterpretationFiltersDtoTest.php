<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\InterpretationFiltersDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for InterpretationFiltersDto.
 *
 * @internal
 */
#[CoversClass(InterpretationFiltersDto::class)]
final class InterpretationFiltersDtoTest extends TestCase
{
    public function testConstructorWithDefaults(): void
    {
        $dto = new InterpretationFiltersDto();

        self::assertNull($dto->customer);
        self::assertNull($dto->project);
        self::assertNull($dto->user);
        self::assertNull($dto->activity);
        self::assertNull($dto->team);
        self::assertNull($dto->ticket);
        self::assertNull($dto->description);
        self::assertNull($dto->datestart);
        self::assertNull($dto->dateend);
        self::assertNull($dto->year);
        self::assertNull($dto->month);
        self::assertNull($dto->maxResults);
        self::assertNull($dto->page);
        self::assertNull($dto->start);
    }

    public function testFromRequestWithAllParameters(): void
    {
        $request = new Request([
            'customer' => '1',
            'project' => '2',
            'user' => '3',
            'activity' => '4',
            'team' => '5',
            'ticket' => 'TICKET-123',
            'description' => 'test description',
            'datestart' => '2024-01-01',
            'dateend' => '2024-01-31',
            'year' => '2024',
            'month' => '01',
            'maxResults' => '50',
            'page' => '1',
            'start' => '0',
        ]);

        $dto = InterpretationFiltersDto::fromRequest($request);

        self::assertSame(1, $dto->customer);
        self::assertSame(2, $dto->project);
        self::assertSame(3, $dto->user);
        self::assertSame(4, $dto->activity);
        self::assertSame(5, $dto->team);
        self::assertSame('TICKET-123', $dto->ticket);
        self::assertSame('test description', $dto->description);
        self::assertSame('2024-01-01', $dto->datestart);
        self::assertSame('2024-01-31', $dto->dateend);
        self::assertSame('2024', $dto->year);
        self::assertSame('01', $dto->month);
        self::assertSame(50, $dto->maxResults);
        self::assertSame(1, $dto->page);
        self::assertSame(0, $dto->start);
    }

    public function testFromRequestWithLegacyAliases(): void
    {
        $request = new Request([
            'customer_id' => '10',
            'project_id' => '20',
            'activity_id' => '30',
        ]);

        $dto = InterpretationFiltersDto::fromRequest($request);

        self::assertSame(10, $dto->customer_id);
        self::assertSame(20, $dto->project_id);
        self::assertSame(30, $dto->activity_id);
    }

    public function testFromRequestWithEmptyValues(): void
    {
        $request = new Request([
            'customer' => '',
            'project' => '',
            'ticket' => '',
            'description' => '   ',
        ]);

        $dto = InterpretationFiltersDto::fromRequest($request);

        self::assertNull($dto->customer);
        self::assertNull($dto->project);
        self::assertNull($dto->ticket);
        self::assertNull($dto->description);
    }

    public function testFromRequestWithNonNumericIntValues(): void
    {
        $request = new Request([
            'customer' => 'invalid',
            'project' => 'abc',
        ]);

        $dto = InterpretationFiltersDto::fromRequest($request);

        self::assertNull($dto->customer);
        self::assertNull($dto->project);
    }

    public function testToFilterArray(): void
    {
        $dto = new InterpretationFiltersDto(
            customer: 1,
            project: 2,
            user: 3,
            activity: 4,
            team: 5,
            ticket: 'TICKET-123',
            description: 'test',
            datestart: '2024-01-01',
            dateend: '2024-01-31',
            maxResults: 50,
            page: 2,
            start: 10,
        );

        $filterArray = $dto->toFilterArray(visibilityUserId: 100);

        self::assertSame(1, $filterArray['customer']);
        self::assertSame(2, $filterArray['project']);
        self::assertSame(3, $filterArray['user']);
        self::assertSame(4, $filterArray['activity']);
        self::assertSame(5, $filterArray['team']);
        self::assertSame('TICKET-123', $filterArray['ticket']);
        self::assertSame('test', $filterArray['description']);
        self::assertSame('2024-01-01', $filterArray['datestart']);
        self::assertSame('2024-01-31', $filterArray['dateend']);
        self::assertSame(100, $filterArray['visibility_user']);
        self::assertSame(50, $filterArray['maxResults']);
        self::assertSame(2, $filterArray['page']);
        self::assertSame(10, $filterArray['start']);
    }

    public function testToFilterArrayUsesLegacyAliasesAsFallback(): void
    {
        $dto = new InterpretationFiltersDto(
            customer_id: 10,
            project_id: 20,
            activity_id: 30,
        );

        $filterArray = $dto->toFilterArray(visibilityUserId: null);

        self::assertSame(10, $filterArray['customer']);
        self::assertSame(20, $filterArray['project']);
        self::assertSame(30, $filterArray['activity']);
    }

    public function testToFilterArrayOverridesMaxResults(): void
    {
        $dto = new InterpretationFiltersDto(maxResults: 50);

        $filterArray = $dto->toFilterArray(visibilityUserId: null, overrideMaxResults: 100);

        self::assertSame(100, $filterArray['maxResults']);
    }

    public function testToFilterArrayPrefersExplicitOverLegacy(): void
    {
        $dto = new InterpretationFiltersDto(
            customer: 1,
            customer_id: 10,
            project: 2,
            project_id: 20,
            activity: 3,
            activity_id: 30,
        );

        $filterArray = $dto->toFilterArray(visibilityUserId: null);

        self::assertSame(1, $filterArray['customer']);
        self::assertSame(2, $filterArray['project']);
        self::assertSame(3, $filterArray['activity']);
    }

    public function testFromRequestWithScalarNonStringValues(): void
    {
        // Test that scalar values are properly converted
        $request = new Request([
            'ticket' => 123, // integer instead of string
        ]);

        $dto = InterpretationFiltersDto::fromRequest($request);

        self::assertSame('123', $dto->ticket);
    }
}
