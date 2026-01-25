<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\BillingType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Project entity.
 *
 * @internal
 */
#[CoversClass(Project::class)]
final class ProjectTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorInitializesCollections(): void
    {
        $project = new Project();

        self::assertCount(0, $project->getEntries());
        self::assertCount(0, $project->getPresets());
    }

    // ==================== ID tests ====================

    public function testIdIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getId());
    }

    public function testSetIdReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setId(42);

        self::assertSame($project, $result);
        self::assertSame(42, $project->getId());
    }

    // ==================== Name tests ====================

    public function testNameIsEmptyByDefault(): void
    {
        $project = new Project();

        self::assertSame('', $project->getName());
    }

    public function testSetNameReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setName('foobar project');

        self::assertSame($project, $result);
        self::assertSame('foobar project', $project->getName());
    }

    // ==================== Active tests ====================

    public function testActiveIsFalseByDefault(): void
    {
        $project = new Project();

        self::assertFalse($project->getActive());
    }

    public function testSetActiveReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setActive(true);

        self::assertSame($project, $result);
        self::assertTrue($project->getActive());
    }

    // ==================== Global tests ====================

    public function testGlobalIsFalseByDefault(): void
    {
        $project = new Project();

        self::assertFalse($project->getGlobal());
    }

    public function testSetGlobalReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setGlobal(true);

        self::assertSame($project, $result);
        self::assertTrue($project->getGlobal());
    }

    // ==================== Customer tests ====================

    public function testCustomerIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getCustomer());
    }

    public function testSetCustomerReturnsFluentInterface(): void
    {
        $project = new Project();
        $customer = new Customer();

        $result = $project->setCustomer($customer);

        self::assertSame($project, $result);
        self::assertSame($customer, $project->getCustomer());
    }

    public function testSetCustomerToNull(): void
    {
        $project = new Project();
        $customer = new Customer();
        $project->setCustomer($customer);

        $project->setCustomer(null);

        self::assertNull($project->getCustomer());
    }

    // ==================== Offer tests ====================

    public function testOfferIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getOffer());
    }

    public function testSetOfferReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setOffer('20130322002_test_4711');

        self::assertSame($project, $result);
        self::assertSame('20130322002_test_4711', $project->getOffer());
    }

    // ==================== AdditionalInformationFromExternal tests ====================

    public function testAdditionalInformationFromExternalIsFalseByDefault(): void
    {
        $project = new Project();

        self::assertFalse($project->getAdditionalInformationFromExternal());
    }

    public function testSetAdditionalInformationFromExternalReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setAdditionalInformationFromExternal(true);

        self::assertSame($project, $result);
        self::assertTrue($project->getAdditionalInformationFromExternal());
    }

    // ==================== JiraId tests ====================

    public function testJiraIdIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getJiraId());
    }

    public function testSetJiraIdReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setJiraId('TEST');

        self::assertSame($project, $result);
        self::assertSame('TEST', $project->getJiraId());
    }

    // ==================== JiraTicket tests ====================

    public function testJiraTicketIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getJiraTicket());
    }

    public function testSetJiraTicketReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setJiraTicket('TEST-123');

        self::assertSame($project, $result);
        self::assertSame('TEST-123', $project->getJiraTicket());
    }

    // ==================== Subtickets tests ====================

    public function testSubticketsIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getSubtickets());
    }

    public function testSetSubticketsReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setSubtickets('TEST-1,TEST-2,TEST-3');

        self::assertSame($project, $result);
        self::assertSame('TEST-1,TEST-2,TEST-3', $project->getSubtickets());
    }

    // ==================== ProjectLead tests ====================

    public function testProjectLeadIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getProjectLead());
    }

    public function testSetProjectLeadReturnsFluentInterface(): void
    {
        $project = new Project();
        $projectLead = new User();
        $projectLead->setId(14);

        $result = $project->setProjectLead($projectLead);

        self::assertSame($project, $result);
        self::assertSame($projectLead, $project->getProjectLead());
    }

    // ==================== TechnicalLead tests ====================

    public function testTechnicalLeadIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getTechnicalLead());
    }

    public function testSetTechnicalLeadReturnsFluentInterface(): void
    {
        $project = new Project();
        $technicalLead = new User();
        $technicalLead->setId(15);

        $result = $project->setTechnicalLead($technicalLead);

        self::assertSame($project, $result);
        self::assertSame($technicalLead, $project->getTechnicalLead());
    }

    // ==================== Estimation tests ====================

    public function testEstimationIsZeroByDefault(): void
    {
        $project = new Project();

        self::assertSame(0, $project->getEstimation());
    }

    public function testSetEstimationReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setEstimation(2500);

        self::assertSame($project, $result);
        self::assertSame(2500, $project->getEstimation());
    }

    // ==================== CostCenter tests ====================

    public function testCostCenterIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getCostCenter());
    }

    public function testSetCostCenterReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setCostCenter('12345');

        self::assertSame($project, $result);
        self::assertSame('12345', $project->getCostCenter());
    }

    // ==================== Billing tests ====================

    public function testBillingIsNoneByDefault(): void
    {
        $project = new Project();

        self::assertSame(BillingType::NONE, $project->getBilling());
    }

    public function testSetBillingReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setBilling(BillingType::TIME_AND_MATERIAL);

        self::assertSame($project, $result);
        self::assertSame(BillingType::TIME_AND_MATERIAL, $project->getBilling());
    }

    // ==================== Invoice tests ====================

    public function testInvoiceIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getInvoice());
    }

    public function testSetInvoiceReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setInvoice('20130122456');

        self::assertSame($project, $result);
        self::assertSame('20130122456', $project->getInvoice());
    }

    // ==================== TicketSystem tests ====================

    public function testTicketSystemIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getTicketSystem());
    }

    public function testSetTicketSystemReturnsFluentInterface(): void
    {
        $project = new Project();
        $ticketSystem = new TicketSystem();

        $result = $project->setTicketSystem($ticketSystem);

        self::assertSame($project, $result);
        self::assertSame($ticketSystem, $project->getTicketSystem());
    }

    // ==================== InternalReference tests ====================

    public function testInternalReferenceIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getInternalReference());
    }

    public function testSetInternalReferenceReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setInternalReference('INT-REF-001');

        self::assertSame($project, $result);
        self::assertSame('INT-REF-001', $project->getInternalReference());
    }

    // ==================== ExternalReference tests ====================

    public function testExternalReferenceIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getExternalReference());
    }

    public function testSetExternalReferenceReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setExternalReference('EXT-REF-001');

        self::assertSame($project, $result);
        self::assertSame('EXT-REF-001', $project->getExternalReference());
    }

    // ==================== InternalJiraProjectKey tests ====================

    public function testInternalJiraProjectKeyIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getInternalJiraProjectKey());
    }

    public function testSetInternalJiraProjectKeyReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setInternalJiraProjectKey('INTERNAL');

        self::assertSame($project, $result);
        self::assertSame('INTERNAL', $project->getInternalJiraProjectKey());
    }

    // ==================== InternalJiraTicketSystem tests ====================

    public function testInternalJiraTicketSystemIsNullByDefault(): void
    {
        $project = new Project();

        self::assertNull($project->getInternalJiraTicketSystem());
    }

    public function testSetInternalJiraTicketSystemReturnsFluentInterface(): void
    {
        $project = new Project();

        $result = $project->setInternalJiraTicketSystem('1');

        self::assertSame($project, $result);
        self::assertSame('1', $project->getInternalJiraTicketSystem());
    }

    public function testSetInternalJiraTicketSystemNormalizesEmptyString(): void
    {
        $project = new Project();
        $project->setInternalJiraTicketSystem('1');

        $project->setInternalJiraTicketSystem('');

        self::assertNull($project->getInternalJiraTicketSystem());
    }

    public function testSetInternalJiraTicketSystemAcceptsNull(): void
    {
        $project = new Project();
        $project->setInternalJiraTicketSystem('1');

        $project->setInternalJiraTicketSystem(null);

        self::assertNull($project->getInternalJiraTicketSystem());
    }

    // ==================== hasInternalJiraProjectKey tests ====================

    public function testHasInternalJiraProjectKeyReturnsFalseWhenBothNull(): void
    {
        $project = new Project();

        self::assertFalse($project->hasInternalJiraProjectKey());
    }

    public function testHasInternalJiraProjectKeyReturnsFalseWhenKeyEmpty(): void
    {
        $project = new Project();
        $project->setInternalJiraProjectKey('');
        $project->setInternalJiraTicketSystem('1');

        self::assertFalse($project->hasInternalJiraProjectKey());
    }

    public function testHasInternalJiraProjectKeyReturnsFalseWhenSystemEmpty(): void
    {
        $project = new Project();
        $project->setInternalJiraProjectKey('INTERNAL');
        $project->setInternalJiraTicketSystem('');

        self::assertFalse($project->hasInternalJiraProjectKey());
    }

    public function testHasInternalJiraProjectKeyReturnsTrueWhenBothSet(): void
    {
        $project = new Project();
        $project->setInternalJiraProjectKey('INTERNAL');
        $project->setInternalJiraTicketSystem('1');

        self::assertTrue($project->hasInternalJiraProjectKey());
    }

    // ==================== matchesInternalJiraProject tests ====================

    public function testMatchesInternalJiraProjectReturnsFalseWhenNotConfigured(): void
    {
        $project = new Project();

        self::assertFalse($project->matchesInternalJiraProject('INTERNAL'));
    }

    public function testMatchesInternalJiraProjectReturnsTrueForExactMatch(): void
    {
        $project = new Project();
        $project->setInternalJiraProjectKey('INTERNAL');
        $project->setInternalJiraTicketSystem('1');

        self::assertTrue($project->matchesInternalJiraProject('INTERNAL'));
    }

    public function testMatchesInternalJiraProjectReturnsFalseForNoMatch(): void
    {
        $project = new Project();
        $project->setInternalJiraProjectKey('INTERNAL');
        $project->setInternalJiraTicketSystem('1');

        self::assertFalse($project->matchesInternalJiraProject('OTHER'));
    }

    public function testMatchesInternalJiraProjectSupportsCommaSeparatedList(): void
    {
        $project = new Project();
        $project->setInternalJiraProjectKey('INT1, INT2, INT3');
        $project->setInternalJiraTicketSystem('1');

        self::assertTrue($project->matchesInternalJiraProject('INT2'));
        self::assertFalse($project->matchesInternalJiraProject('INT4'));
    }

    // ==================== toArray tests ====================

    public function testToArrayIncludesEstimationText(): void
    {
        $project = new Project();
        $project->setId(1);
        $project->setName('Test Project');
        $project->setEstimation(120); // 2 hours

        $array = $project->toArray();

        self::assertArrayHasKey('estimationText', $array);
        self::assertIsString($array['estimationText']);
    }
}
