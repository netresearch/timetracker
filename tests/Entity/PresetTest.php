<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Preset;
use App\Entity\Project;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Preset entity.
 *
 * @internal
 */
#[CoversClass(Preset::class)]
final class PresetTest extends TestCase
{
    // ==================== ID tests ====================

    public function testIdIsNullByDefault(): void
    {
        $preset = new Preset();

        self::assertNull($preset->getId());
    }

    public function testSetIdReturnsFluentInterface(): void
    {
        $preset = new Preset();

        $result = $preset->setId(42);

        self::assertSame($preset, $result);
        self::assertSame(42, $preset->getId());
    }

    // ==================== Name tests ====================

    public function testSetNameReturnsFluentInterface(): void
    {
        $preset = new Preset();

        $result = $preset->setName('Daily Standup');

        self::assertSame($preset, $result);
        self::assertSame('Daily Standup', $preset->getName());
    }

    // ==================== Description tests ====================

    public function testSetDescriptionReturnsFluentInterface(): void
    {
        $preset = new Preset();

        $result = $preset->setDescription('Morning standup meeting');

        self::assertSame($preset, $result);
        self::assertSame('Morning standup meeting', $preset->getDescription());
    }

    public function testSetDescriptionAllowsEmptyString(): void
    {
        $preset = new Preset();
        $preset->setDescription('Initial');

        $preset->setDescription('');

        self::assertSame('', $preset->getDescription());
    }

    // ==================== Customer tests ====================

    public function testSetCustomerReturnsFluentInterface(): void
    {
        $preset = new Preset();
        $customer = new Customer();
        $customer->setId(10);

        $result = $preset->setCustomer($customer);

        self::assertSame($preset, $result);
        self::assertSame($customer, $preset->getCustomer());
    }

    public function testGetCustomerReturnsNewCustomerWhenNotSet(): void
    {
        $preset = new Preset();

        $customer = $preset->getCustomer();

        // Returns new Customer() when not set
        self::assertNull($customer->getId());
    }

    public function testGetCustomerIdReturnsIdFromCustomer(): void
    {
        $preset = new Preset();
        $customer = new Customer();
        $customer->setId(25);
        $preset->setCustomer($customer);

        self::assertSame(25, $preset->getCustomerId());
    }

    // ==================== Project tests ====================

    public function testSetProjectReturnsFluentInterface(): void
    {
        $preset = new Preset();
        $project = new Project();
        $project->setId(15);

        $result = $preset->setProject($project);

        self::assertSame($preset, $result);
        self::assertSame($project, $preset->getProject());
    }

    public function testGetProjectReturnsNewProjectWhenNotSet(): void
    {
        $preset = new Preset();

        $project = $preset->getProject();

        // Returns new Project() when not set
        self::assertNull($project->getId());
    }

    public function testGetProjectIdReturnsIdFromProject(): void
    {
        $preset = new Preset();
        $project = new Project();
        $project->setId(30);
        $preset->setProject($project);

        self::assertSame(30, $preset->getProjectId());
    }

    public function testGetProjectIdReturnsZeroWhenProjectIdIsNull(): void
    {
        $preset = new Preset();
        $project = new Project(); // ID is null
        $preset->setProject($project);

        self::assertSame(0, $preset->getProjectId());
    }

    // ==================== Activity tests ====================

    public function testSetActivityReturnsFluentInterface(): void
    {
        $preset = new Preset();
        $activity = new Activity();
        $activity->setId(20);

        $result = $preset->setActivity($activity);

        self::assertSame($preset, $result);
        self::assertSame($activity, $preset->getActivity());
    }

    public function testGetActivityReturnsNewActivityWhenNotSet(): void
    {
        $preset = new Preset();

        $activity = $preset->getActivity();

        // Returns new Activity() when not set
        self::assertNull($activity->getId());
    }

    public function testGetActivityIdReturnsIdFromActivity(): void
    {
        $preset = new Preset();
        $activity = new Activity();
        $activity->setId(35);
        $preset->setActivity($activity);

        self::assertSame(35, $preset->getActivityId());
    }

    // ==================== toArray tests ====================

    public function testToArrayReturnsCorrectStructure(): void
    {
        $preset = new Preset();
        $preset->setId(100);
        $preset->setName('Test Preset');
        $preset->setDescription('Test Description');

        $customer = new Customer();
        $customer->setId(10);
        $preset->setCustomer($customer);

        $project = new Project();
        $project->setId(20);
        $preset->setProject($project);

        $activity = new Activity();
        $activity->setId(30);
        $preset->setActivity($activity);

        $array = $preset->toArray();

        self::assertSame(100, $array['id']);
        self::assertSame('Test Preset', $array['name']);
        self::assertSame('Test Description', $array['description']);
        self::assertSame(10, $array['customer']);
        self::assertSame(20, $array['project']);
        self::assertSame(30, $array['activity']);
    }

    public function testToArrayReturnsZeroForNullId(): void
    {
        $preset = new Preset();
        $preset->setName('No ID Preset');
        $preset->setDescription('Description');

        $array = $preset->toArray();

        self::assertSame(0, $array['id']);
    }
}
