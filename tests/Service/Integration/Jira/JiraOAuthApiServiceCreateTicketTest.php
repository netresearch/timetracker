<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\Integration\Jira;

use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiService;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Routing\RouterInterface;
use Tests\Traits\TokenEncryptionTestTrait;

/**
 * The project a mirror issue is created in (#935).
 *
 * @internal
 */
#[CoversClass(JiraOAuthApiService::class)]
final class JiraOAuthApiServiceCreateTicketTest extends TestCase
{
    use TokenEncryptionTestTrait;

    public function testCreatesTheMirrorIssueInTheFirstListedInternalProject(): void
    {
        $service = $this->recordingService();

        $project = new Project();
        $project->setInternalJiraProjectKey('opsdhl, OPSX');
        $entry = new Entry();
        $entry->setProject($project);
        $entry->setTicket('DHLSUP-7');

        $service->createTicket($entry);

        $fields = $service->lastPostData['fields'] ?? null;
        self::assertIsArray($fields);
        self::assertSame(['key' => 'OPSDHL'], $fields['project']);
        self::assertSame('DHLSUP-7', $fields['summary']);
    }

    /**
     * @return JiraOAuthApiService&object{lastPostData: array<string, mixed>}
     */
    private function recordingService(): JiraOAuthApiService
    {
        return new class(self::createStub(User::class), self::createStub(TicketSystem::class), self::createStub(ManagerRegistry::class), self::createStub(RouterInterface::class), $this->createTokenEncryptionService()) extends JiraOAuthApiService {
            /** @var array<string, mixed> */
            public array $lastPostData = [];

            /**
             * @param array<string, mixed> $data
             */
            protected function post(string $url, array $data = []): object
            {
                $this->lastPostData = $data;

                return new stdClass();
            }
        };
    }
}
