<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\ProjectSaveDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for ProjectSaveDto.
 *
 * @internal
 */
#[CoversClass(ProjectSaveDto::class)]
final class ProjectSaveDtoTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithDefaultValues(): void
    {
        $dto = new ProjectSaveDto();

        self::assertSame(0, $dto->id);
        self::assertSame('', $dto->name);
        self::assertNull($dto->customer);
        self::assertNull($dto->jiraId);
        self::assertNull($dto->jiraTicket);
        self::assertFalse($dto->active);
        self::assertFalse($dto->global);
        self::assertSame('0m', $dto->estimation);
        self::assertSame(0, $dto->billing);
        self::assertNull($dto->cost_center);
        self::assertNull($dto->offer);
        self::assertNull($dto->project_lead);
        self::assertNull($dto->technical_lead);
        self::assertNull($dto->ticket_system);
        self::assertFalse($dto->additionalInformationFromExternal);
        self::assertNull($dto->internalJiraTicketSystem);
        self::assertSame('', $dto->internalJiraProjectKey);
    }

    public function testConstructorWithCustomValues(): void
    {
        $dto = new ProjectSaveDto(
            id: 42,
            name: 'Test Project',
            customer: 5,
            jiraId: 'PROJ',
            jiraTicket: 'PROJ-123',
            active: true,
            global: true,
            estimation: '5d',
            billing: 1,
            cost_center: 'CC001',
            offer: 'OFF-001',
            project_lead: 10,
            technical_lead: 20,
            ticket_system: '1',
            additionalInformationFromExternal: true,
            internalJiraTicketSystem: 'internal-jira',
            internalJiraProjectKey: 'INTERNAL',
        );

        self::assertSame(42, $dto->id);
        self::assertSame('Test Project', $dto->name);
        self::assertSame(5, $dto->customer);
        self::assertSame('PROJ', $dto->jiraId);
        self::assertSame('PROJ-123', $dto->jiraTicket);
        self::assertTrue($dto->active);
        self::assertTrue($dto->global);
        self::assertSame('5d', $dto->estimation);
        self::assertSame(1, $dto->billing);
        self::assertSame('CC001', $dto->cost_center);
        self::assertSame('OFF-001', $dto->offer);
        self::assertSame(10, $dto->project_lead);
        self::assertSame(20, $dto->technical_lead);
        self::assertSame('1', $dto->ticket_system);
        self::assertTrue($dto->additionalInformationFromExternal);
        self::assertSame('internal-jira', $dto->internalJiraTicketSystem);
        self::assertSame('INTERNAL', $dto->internalJiraProjectKey);
    }

    // ==================== fromRequest tests ====================

    public function testFromRequestWithMinimalData(): void
    {
        $request = $this->createRequestWithData([]);

        $dto = ProjectSaveDto::fromRequest($request);

        self::assertSame(0, $dto->id);
        self::assertSame('', $dto->name);
        self::assertNull($dto->customer);
        self::assertFalse($dto->active);
    }

    public function testFromRequestWithAllData(): void
    {
        $request = $this->createRequestWithData([
            'id' => '42',
            'name' => 'Test Project',
            'customer' => '5',
            'jiraId' => 'PROJ',
            'jiraTicket' => 'PROJ-123',
            'active' => '1',
            'global' => '1',
            'estimation' => '5d',
            'billing' => '1',
            'cost_center' => 'CC001',
            'offer' => 'OFF-001',
            'project_lead' => '10',
            'technical_lead' => '20',
            'ticket_system' => '1',
            'additionalInformationFromExternal' => '1',
            'internalJiraTicketSystem' => 'internal-jira',
            'internalJiraProjectKey' => 'INTERNAL',
        ]);

        $dto = ProjectSaveDto::fromRequest($request);

        self::assertSame(42, $dto->id);
        self::assertSame('Test Project', $dto->name);
        self::assertSame(5, $dto->customer);
        self::assertSame('PROJ', $dto->jiraId);
        self::assertSame('PROJ-123', $dto->jiraTicket);
        self::assertTrue($dto->active);
        self::assertTrue($dto->global);
        self::assertSame('5d', $dto->estimation);
        self::assertSame(1, $dto->billing);
        self::assertSame('CC001', $dto->cost_center);
        self::assertSame('OFF-001', $dto->offer);
        self::assertSame(10, $dto->project_lead);
        self::assertSame(20, $dto->technical_lead);
        self::assertSame('1', $dto->ticket_system);
        self::assertTrue($dto->additionalInformationFromExternal);
        self::assertSame('internal-jira', $dto->internalJiraTicketSystem);
        self::assertSame('INTERNAL', $dto->internalJiraProjectKey);
    }

    public function testFromRequestWithEmptyInternalJiraTicketSystem(): void
    {
        $request = $this->createRequestWithData([
            'internalJiraTicketSystem' => '',
        ]);

        $dto = ProjectSaveDto::fromRequest($request);

        self::assertNull($dto->internalJiraTicketSystem);
    }

    public function testFromRequestWithNullOptionalFields(): void
    {
        $request = $this->createRequestWithData([
            'id' => '1',
            'name' => 'Test',
            // customer, jiraId, jiraTicket, etc. are null (not in request)
        ]);

        $dto = ProjectSaveDto::fromRequest($request);

        self::assertNull($dto->customer);
        self::assertNull($dto->jiraId);
        self::assertNull($dto->jiraTicket);
        self::assertNull($dto->cost_center);
        self::assertNull($dto->offer);
        self::assertNull($dto->project_lead);
        self::assertNull($dto->technical_lead);
        self::assertNull($dto->ticket_system);
    }

    // ==================== Helper methods ====================

    /**
     * @param array<string, mixed> $data
     */
    private function createRequestWithData(array $data): Request
    {
        $request = new Request();
        $request->request = new InputBag($data);

        return $request;
    }
}
