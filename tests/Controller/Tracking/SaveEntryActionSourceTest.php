<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Tracking;

use App\Controller\Tracking\SaveEntryAction;
use App\Dto\EntrySaveDto;
use App\Entity\Entry;
use App\Entity\User;
use App\Enum\EntrySource;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\ActsAsApiTokenUser;

use function json_decode;

/**
 * ADR-025 §4 attribution gate: `source`/`estimated`/`touchpoints` are trusted
 * ONLY in the API-token (agent) channel. A session request is always a plain
 * human self-log, so a spoofed body cannot mark work as agent (which would drop
 * it from attendance/ArbZG). The responsible user is the token owner, never a
 * client-supplied id (IDOR).
 *
 * @internal
 *
 * @coversNothing
 */
final class SaveEntryActionSourceTest extends AbstractWebTestCase
{
    use ActsAsApiTokenUser;

    private const string DATE = '2024-03-11';

    private const string START = '09:00:00';

    private const string END = '10:00:00';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function saveParameters(array $overrides = []): array
    {
        return $overrides + [
            'date' => self::DATE,
            'start' => self::START,
            'end' => self::END,
            'project_id' => 1,
            'customer_id' => 1,
            'activity_id' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function sessionSave(array $parameters): int
    {
        $this->client->request(Request::METHOD_POST, '/tracking/save', $parameters, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['result']);
        self::assertIsInt($data['result']['id']);

        return $data['result']['id'];
    }

    private function reloadEntry(int $entryId): Entry
    {
        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $manager = $doctrine->getManager();
        $manager->clear();
        $entry = $manager->getRepository(Entry::class)->find($entryId);
        self::assertInstanceOf(Entry::class, $entry);

        return $entry;
    }

    public function testSessionSaveDefaultsToHuman(): void
    {
        $this->logInSession('unittest');

        $entryId = $this->sessionSave($this->saveParameters());
        $entry = $this->reloadEntry($entryId);

        self::assertSame(EntrySource::HUMAN, $entry->getSource());
        self::assertFalse($entry->isEstimated());
        self::assertNull($entry->getTouchpoints());
        self::assertNull($entry->getResponsibleUser());
        self::assertInstanceOf(User::class, $entry->getLoggedBy());
        self::assertSame(1, $entry->getLoggedBy()->getId());
    }

    public function testSessionSaveIgnoresSpoofedAgentSource(): void
    {
        $this->logInSession('unittest');

        // A human tries to mark their own work as agent (and estimated) to drop
        // it out of attendance. The body must be ignored in the session channel.
        $entryId = $this->sessionSave($this->saveParameters([
            'source' => 'agent',
            'estimated' => '1',
            'touchpoints' => ['prompts' => 9],
        ]));
        $entry = $this->reloadEntry($entryId);

        self::assertSame(EntrySource::HUMAN, $entry->getSource());
        self::assertFalse($entry->isEstimated());
        self::assertNull($entry->getTouchpoints());
        self::assertNull($entry->getResponsibleUser());
    }

    public function testApiTokenSaveHonoursAgentSource(): void
    {
        $this->useToken(['entries:write']);
        $user = $this->tokenUser();

        $dto = new EntrySaveDto(
            date: self::DATE,
            start: self::START,
            end: self::END,
            description: 'agent walltime',
            project_id: 1,
            customer_id: 1,
            activity_id: 1,
            source: 'agent',
            estimated: true,
            touchpoints: ['prompts' => 7, 'reviews' => 2],
        );

        $entryId = $this->invokeAction($dto, $user);
        $entry = $this->reloadEntry($entryId);

        self::assertSame(EntrySource::AGENT, $entry->getSource());
        self::assertTrue($entry->isEstimated());
        self::assertSame(['prompts' => 7, 'reviews' => 2], $entry->getTouchpoints());
        self::assertInstanceOf(User::class, $entry->getResponsibleUser());
        self::assertSame(1, $entry->getResponsibleUser()->getId());
    }

    public function testApiTokenSaveKeepsHumanEstimatedDelegatedEntry(): void
    {
        $this->useToken(['entries:write']);
        $user = $this->tokenUser();

        // The delegated human estimate the agent also writes: source stays human
        // (it counts as labour) but estimated/touchpoints are honoured and the
        // token owner is the responsible user.
        $dto = new EntrySaveDto(
            date: self::DATE,
            start: self::START,
            end: self::END,
            description: 'delegated human estimate',
            project_id: 1,
            customer_id: 1,
            activity_id: 1,
            source: 'human',
            estimated: true,
            touchpoints: ['prompts' => 7],
        );

        $entryId = $this->invokeAction($dto, $user);
        $entry = $this->reloadEntry($entryId);

        self::assertSame(EntrySource::HUMAN, $entry->getSource());
        self::assertTrue($entry->isEstimated());
        self::assertSame(['prompts' => 7], $entry->getTouchpoints());
        self::assertInstanceOf(User::class, $entry->getResponsibleUser());
        self::assertSame(1, $entry->getResponsibleUser()->getId());
    }

    public function testSessionEditKeepsAgentAttributionOfExistingAgentEntry(): void
    {
        // The agent logs its walltime through the token channel ...
        $this->useToken(['entries:write']);
        $user = $this->tokenUser();
        $entryId = $this->invokeAction(new EntrySaveDto(
            date: self::DATE,
            start: self::START,
            end: self::END,
            description: 'agent walltime',
            project_id: 1,
            customer_id: 1,
            activity_id: 1,
            source: 'agent',
            estimated: false,
            touchpoints: ['prompts' => 3],
        ), $user);

        // ... then the person corrects it in the web grid. The edit must not
        // silently turn machine time into human labour (ADR-025 §5): the source
        // and the agent attribution survive, only the edited fields change.
        $this->logInSession('unittest');
        $this->sessionSave($this->saveParameters([
            'id' => $entryId,
            'end' => '10:30:00',
            'description' => 'agent walltime (corrected)',
        ]));
        $entry = $this->reloadEntry($entryId);

        self::assertSame(EntrySource::AGENT, $entry->getSource());
        self::assertSame(['prompts' => 3], $entry->getTouchpoints());
        self::assertSame('agent walltime (corrected)', $entry->getDescription());
        self::assertSame('10:30', $entry->getEnd()->format('H:i'));
    }

    public function testTokenUpdateWithoutAttributionKeepsTheStoredAgentAttribution(): void
    {
        // A token client that re-saves an existing agent entry without source (a
        // partial or older client) must not turn machine time into human labour.
        $this->useToken(['entries:write']);
        $user = $this->tokenUser();
        $entryId = $this->invokeAction(new EntrySaveDto(
            date: self::DATE,
            start: self::START,
            end: self::END,
            description: 'agent walltime',
            project_id: 1,
            customer_id: 1,
            activity_id: 1,
            source: 'agent',
            estimated: false,
            touchpoints: ['prompts' => 5],
        ), $user);

        $this->invokeAction(new EntrySaveDto(
            id: $entryId,
            date: self::DATE,
            start: self::START,
            end: '10:15:00',
            description: 'agent walltime (re-saved)',
            project_id: 1,
            customer_id: 1,
            activity_id: 1,
        ), $user);
        $entry = $this->reloadEntry($entryId);

        self::assertSame(EntrySource::AGENT, $entry->getSource());
        self::assertSame(['prompts' => 5], $entry->getTouchpoints());
        self::assertSame('10:15', $entry->getEnd()->format('H:i'));
    }

    public function testTokenUpdateWithoutAttributionKeepsADelegatedEstimate(): void
    {
        $this->useToken(['entries:write']);
        $user = $this->tokenUser();
        $entryId = $this->invokeAction(new EntrySaveDto(
            date: self::DATE,
            start: self::START,
            end: self::END,
            description: 'delegated human estimate',
            project_id: 1,
            customer_id: 1,
            activity_id: 1,
            source: 'human',
            estimated: true,
            touchpoints: ['reviews' => 1],
        ), $user);

        $this->invokeAction(new EntrySaveDto(
            id: $entryId,
            date: self::DATE,
            start: self::START,
            end: self::END,
            description: 'delegated human estimate (re-saved)',
            project_id: 1,
            customer_id: 1,
            activity_id: 1,
        ), $user);
        $entry = $this->reloadEntry($entryId);

        self::assertSame(EntrySource::HUMAN, $entry->getSource());
        self::assertTrue($entry->isEstimated());
        self::assertSame(['reviews' => 1], $entry->getTouchpoints());
    }

    public function testSessionEditOfEstimatedHumanEntryConfirmsIt(): void
    {
        // Pins behaviour that predates this change (a guard against regressions,
        // not evidence for the fix): the delegated human estimate stays human, and a
        // person editing it in the web grid takes ownership of the figure, so it is
        // no longer an estimate.
        $this->useToken(['entries:write']);
        $user = $this->tokenUser();
        $entryId = $this->invokeAction(new EntrySaveDto(
            date: self::DATE,
            start: self::START,
            end: self::END,
            description: 'delegated human estimate',
            project_id: 1,
            customer_id: 1,
            activity_id: 1,
            source: 'human',
            estimated: true,
            touchpoints: ['prompts' => 7],
        ), $user);

        $this->logInSession('unittest');
        $this->sessionSave($this->saveParameters(['id' => $entryId, 'end' => '09:45:00']));
        $entry = $this->reloadEntry($entryId);

        self::assertSame(EntrySource::HUMAN, $entry->getSource());
        self::assertFalse($entry->isEstimated());
        // The confirmed figure is the person's own now; the agent's signals go with the estimate.
        self::assertNull($entry->getTouchpoints());
    }

    private function tokenUser(): User
    {
        $user = self::getContainer()->get(UserRepository::class)->find(1);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private function invokeAction(EntrySaveDto $dto, User $user): int
    {
        $response = self::getContainer()->get(SaveEntryAction::class)($dto, $user);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['result']);
        self::assertIsInt($data['result']['id']);

        return $data['result']['id'];
    }
}
