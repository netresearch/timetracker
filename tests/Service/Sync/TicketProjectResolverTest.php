<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Sync;

use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Repository\ProjectRepository;
use App\Service\Sync\TicketProjectResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TicketProjectResolver::class)]
#[AllowMockObjectsWithoutExpectations]
final class TicketProjectResolverTest extends TestCase
{
    private function project(int $id, ?string $jiraId, string $subtickets = ''): Project
    {
        $project = self::createStub(Project::class);
        $project->method('getId')->willReturn($id);
        $project->method('getJiraId')->willReturn($jiraId);
        $project->method('getSubtickets')->willReturn($subtickets);

        return $project;
    }

    /**
     * @param list<Project> $projects
     */
    private function resolver(array $projects): TicketProjectResolver
    {
        $projectRepository = $this->createMock(ProjectRepository::class);
        $projectRepository->method('findByTicketSystem')->willReturn($projects);

        return new TicketProjectResolver($projectRepository);
    }

    private function ticketSystem(): TicketSystem
    {
        $ticketSystem = self::createStub(TicketSystem::class);
        $ticketSystem->method('getId')->willReturn(1);

        return $ticketSystem;
    }

    public function testPrefixMatchResolvesProject(): void
    {
        $resolution = $this->resolver([$this->project(1, 'SA'), $this->project(2, 'TIM, OPS')])
            ->resolve('TIM-42', $this->ticketSystem());

        self::assertSame(2, $resolution->project?->getId());
    }

    public function testExactSubticketWinsOverPrefix(): void
    {
        $byPrefix = $this->project(1, 'TIM');
        $bySubticket = $this->project(2, 'SA', 'TIM-42, TIM-43');

        $resolution = $this->resolver([$byPrefix, $bySubticket])->resolve('TIM-42', $this->ticketSystem());

        self::assertSame(2, $resolution->project?->getId());
    }

    public function testNoMatchParks(): void
    {
        $resolution = $this->resolver([$this->project(1, 'SA')])->resolve('TIM-42', $this->ticketSystem());

        self::assertNull($resolution->project);
        self::assertStringContainsString('no project', $resolution->reason);
    }

    public function testAmbiguousPrefixParks(): void
    {
        $resolution = $this->resolver([$this->project(1, 'TIM'), $this->project(2, 'TIM')])
            ->resolve('TIM-42', $this->ticketSystem());

        self::assertNull($resolution->project);
        self::assertStringContainsString('ambiguous', $resolution->reason);
    }

    public function testSubticketMatchIsCaseInsensitive(): void
    {
        $resolution = $this->resolver([$this->project(1, 'SA', 'tim-42')])->resolve('TIM-42', $this->ticketSystem());

        self::assertSame(1, $resolution->project?->getId());
    }
}
