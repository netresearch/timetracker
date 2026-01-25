<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Preset;
use App\Entity\Project;
use App\Entity\Team;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Customer entity.
 *
 * @internal
 */
#[CoversClass(Customer::class)]
final class CustomerTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorInitializesCollections(): void
    {
        $customer = new Customer();

        self::assertCount(0, $customer->getProjects());
        self::assertCount(0, $customer->getEntries());
        self::assertCount(0, $customer->getTeams());
        self::assertCount(0, $customer->getPresets());
    }

    // ==================== ID tests ====================

    public function testIdIsNullByDefault(): void
    {
        $customer = new Customer();

        self::assertNull($customer->getId());
    }

    public function testSetIdReturnsFluentInterface(): void
    {
        $customer = new Customer();

        $result = $customer->setId(42);

        self::assertSame($customer, $result);
        self::assertSame(42, $customer->getId());
    }

    // ==================== Name tests ====================

    public function testNameIsEmptyByDefault(): void
    {
        $customer = new Customer();

        self::assertSame('', $customer->getName());
    }

    public function testSetNameReturnsFluentInterface(): void
    {
        $customer = new Customer();

        $result = $customer->setName('Acme Corp');

        self::assertSame($customer, $result);
        self::assertSame('Acme Corp', $customer->getName());
    }

    // ==================== Active tests ====================

    public function testActiveIsFalseByDefault(): void
    {
        $customer = new Customer();

        self::assertFalse($customer->getActive());
    }

    public function testSetActiveReturnsFluentInterface(): void
    {
        $customer = new Customer();

        $result = $customer->setActive(true);

        self::assertSame($customer, $result);
        self::assertTrue($customer->getActive());
    }

    // ==================== Global tests ====================

    public function testGlobalIsFalseByDefault(): void
    {
        $customer = new Customer();

        self::assertFalse($customer->getGlobal());
    }

    public function testSetGlobalReturnsFluentInterface(): void
    {
        $customer = new Customer();

        $result = $customer->setGlobal(true);

        self::assertSame($customer, $result);
        self::assertTrue($customer->getGlobal());
    }

    // ==================== Projects tests ====================

    public function testAddProjectsReturnsFluentInterface(): void
    {
        $customer = new Customer();
        $project = new Project();

        $result = $customer->addProjects($project);

        self::assertSame($customer, $result);
        self::assertCount(1, $customer->getProjects());
    }

    public function testAddProjectReturnsFluentInterface(): void
    {
        $customer = new Customer();
        $project = new Project();

        $result = $customer->addProject($project);

        self::assertSame($customer, $result);
        self::assertCount(1, $customer->getProjects());
    }

    public function testAddMultipleProjects(): void
    {
        $customer = new Customer();
        $project1 = new Project();
        $project2 = new Project();

        $customer->addProject($project1);
        $customer->addProject($project2);

        self::assertCount(2, $customer->getProjects());
    }

    public function testRemoveProject(): void
    {
        $customer = new Customer();
        $project = new Project();
        $customer->addProject($project);

        $customer->removeProject($project);

        self::assertCount(0, $customer->getProjects());
    }

    // ==================== Entries tests ====================

    public function testAddEntryReturnsFluentInterface(): void
    {
        $customer = new Customer();
        $entry = new Entry();

        $result = $customer->addEntry($entry);

        self::assertSame($customer, $result);
        self::assertCount(1, $customer->getEntries());
    }

    public function testRemoveEntry(): void
    {
        $customer = new Customer();
        $entry = new Entry();
        $customer->addEntry($entry);

        $customer->removeEntry($entry);

        self::assertCount(0, $customer->getEntries());
    }

    // ==================== Teams tests ====================

    public function testAddTeamReturnsFluentInterface(): void
    {
        $customer = new Customer();
        $team = new Team();

        $result = $customer->addTeam($team);

        self::assertSame($customer, $result);
        self::assertCount(1, $customer->getTeams());
    }

    public function testAddMultipleTeams(): void
    {
        $customer = new Customer();
        $team1 = new Team();
        $team2 = new Team();

        $customer->addTeam($team1);
        $customer->addTeam($team2);

        self::assertCount(2, $customer->getTeams());
    }

    public function testRemoveTeam(): void
    {
        $customer = new Customer();
        $team = new Team();
        $customer->addTeam($team);

        $customer->removeTeam($team);

        self::assertCount(0, $customer->getTeams());
    }

    public function testResetTeamsReturnsFluentInterface(): void
    {
        $customer = new Customer();
        $team = new Team();
        $customer->addTeam($team);

        $result = $customer->resetTeams();

        self::assertSame($customer, $result);
        self::assertCount(0, $customer->getTeams());
    }

    // ==================== Presets tests ====================

    public function testAddPresetReturnsFluentInterface(): void
    {
        $customer = new Customer();
        $preset = new Preset();

        $result = $customer->addPreset($preset);

        self::assertSame($customer, $result);
        self::assertCount(1, $customer->getPresets());
    }

    public function testRemovePreset(): void
    {
        $customer = new Customer();
        $preset = new Preset();
        $customer->addPreset($preset);

        $customer->removePreset($preset);

        self::assertCount(0, $customer->getPresets());
    }
}
