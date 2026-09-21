<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\DTO\Jira;

use App\DTO\Jira\JiraWorkLogPayloadDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The body of a Jira "add worklog" call. Jira reads the three keys by name, so
 * the test pins the names, not just the values.
 *
 * @internal
 */
#[CoversClass(JiraWorkLogPayloadDto::class)]
final class JiraWorkLogPayloadDtoTest extends TestCase
{
    public function testToArrayCarriesTheThreeFieldsJiraExpects(): void
    {
        $jiraWorkLogPayloadDto = new JiraWorkLogPayloadDto(
            'TT-42: review',
            '2026-09-21T14:35:07.000+0200',
            5400,
        );

        self::assertSame(
            [
                'comment' => 'TT-42: review',
                'started' => '2026-09-21T14:35:07.000+0200',
                'timeSpentSeconds' => 5400,
            ],
            $jiraWorkLogPayloadDto->toArray(),
        );
    }

    public function testAnEmptyCommentAndAZeroDurationSurvive(): void
    {
        $jiraWorkLogPayloadDto = new JiraWorkLogPayloadDto('', '2026-09-21T00:00:00.000+0200', 0);

        $payload = $jiraWorkLogPayloadDto->toArray();

        self::assertSame('', $payload['comment']);
        self::assertSame(0, $payload['timeSpentSeconds']);
    }

    public function testThePropertiesAreReadableDirectly(): void
    {
        $jiraWorkLogPayloadDto = new JiraWorkLogPayloadDto('note', '2026-09-21T09:00:00.000+0200', 60);

        self::assertSame('note', $jiraWorkLogPayloadDto->comment);
        self::assertSame('2026-09-21T09:00:00.000+0200', $jiraWorkLogPayloadDto->started);
        self::assertSame(60, $jiraWorkLogPayloadDto->timeSpentSeconds);
    }
}
