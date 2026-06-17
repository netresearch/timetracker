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
        self::assertNull($dto->invoice);
        self::assertNull($dto->internalReference);
        self::assertNull($dto->externalReference);
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
            ticket_system: 1,
            additionalInformationFromExternal: true,
            internalJiraTicketSystem: 'internal-jira',
            internalJiraProjectKey: 'INTERNAL',
        );

        $this->assertSampleProjectDto($dto);
    }

    public function testTicketSystemIsIntForJsonPayload(): void
    {
        // The SolidJS admin sends ticket_system as a numeric select id via a JSON
        // body bound by #[MapRequestPayload]; the property must be int so that
        // validation accepts it (a ?string property rejected the number, which is
        // what produced the "should be of type null|string" save error). Sibling
        // FK selects (customer/project_lead/technical_lead) are int for the same reason.
        $dto = new ProjectSaveDto(ticket_system: 7);

        self::assertSame(7, $dto->ticket_system);
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

        $this->assertSampleProjectDto($dto);
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

    public function testReferenceFieldsRoundTrip(): void
    {
        // Constructor stores the invoice / internal / external reference fields.
        $constructed = new ProjectSaveDto(
            invoice: 'INV-2026-001',
            internalReference: 'INT-REF-9',
            externalReference: 'EXT-REF-7',
        );
        self::assertSame('INV-2026-001', $constructed->invoice);
        self::assertSame('INT-REF-9', $constructed->internalReference);
        self::assertSame('EXT-REF-7', $constructed->externalReference);

        // fromRequest maps them off the request payload.
        $mapped = ProjectSaveDto::fromRequest($this->createRequestWithData([
            'invoice' => 'INV-FROM-REQ',
            'internalReference' => 'INT-FROM-REQ',
            'externalReference' => 'EXT-FROM-REQ',
        ]));
        self::assertSame('INV-FROM-REQ', $mapped->invoice);
        self::assertSame('INT-FROM-REQ', $mapped->internalReference);
        self::assertSame('EXT-FROM-REQ', $mapped->externalReference);
    }

    // ==================== Helper methods ====================

    /**
     * Assert the field values shared by the constructor and fromRequest "all
     * data" cases (kept in one place so the two callers don't duplicate the
     * full assertion block).
     */
    private function assertSampleProjectDto(ProjectSaveDto $dto): void
    {
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
        self::assertSame(1, $dto->ticket_system);
        self::assertTrue($dto->additionalInformationFromExternal);
        self::assertSame('internal-jira', $dto->internalJiraTicketSystem);
        self::assertSame('INTERNAL', $dto->internalJiraProjectKey);
    }

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
