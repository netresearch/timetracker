<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\Base;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function array_key_exists;

/**
 * Test model with all properties having getters.
 */
class TestModel extends Base
{
    protected string $name = 'Name';

    protected int $id = 500;

    protected string $workspace = 'internal';

    protected bool $active = true;

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getWorkspace(): string
    {
        return $this->workspace;
    }

    public function getActive(): bool
    {
        return $this->active;
    }
}

/**
 * Test model with a property that has no getter (should be skipped).
 */
class TestModelWithMissingGetter extends Base
{
    protected string $name = 'Name';

    protected string $noGetter = 'This has no getter';

    public function getName(): string
    {
        return $this->name;
    }

    // Note: no getNoGetter() method exists
}

/**
 * Test entity class that has getId() method.
 */
class TestEntityWithId
{
    public function getId(): int
    {
        return 42;
    }
}

/**
 * Test model that contains an object with getId() method.
 */
class TestModelWithEntity extends Base
{
    protected TestEntityWithId $entity;

    public function __construct()
    {
        $this->entity = new TestEntityWithId();
    }

    public function getEntity(): TestEntityWithId
    {
        return $this->entity;
    }
}

/**
 * Test enum for testing enum handling.
 */
enum TestStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}

/**
 * Test model that contains an enum.
 */
class TestModelWithEnum extends Base
{
    protected TestStatus $status;

    public function __construct()
    {
        $this->status = TestStatus::Active;
    }

    public function getStatus(): TestStatus
    {
        return $this->status;
    }
}

/**
 * Test model with camelCase property name.
 */
class TestModelWithCamelCase extends Base
{
    protected string $userName = 'john_doe';

    protected string $emailAddress = 'john@example.com';

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function getEmailAddress(): string
    {
        return $this->emailAddress;
    }
}

/**
 * Unit tests for Base model class.
 *
 * @internal
 */
#[CoversClass(Base::class)]
final class BaseTest extends TestCase
{
    public function testBaseModelByTestModel(): void
    {
        $testModel = new TestModel();
        $result = $testModel->toArray();

        // Properties without uppercase letters have same key in snake_case, so 4 unique keys
        self::assertCount(4, $result);
        self::assertTrue(array_key_exists('id', $result));
        self::assertSame(500, $result['id']);
        self::assertTrue($result['active']);
    }

    public function testToArraySkipsPropertiesWithoutGetter(): void
    {
        $model = new TestModelWithMissingGetter();
        $result = $model->toArray();

        // Should have 'name' but NOT 'noGetter' since it lacks a getter
        self::assertArrayHasKey('name', $result);
        self::assertArrayNotHasKey('noGetter', $result);
        self::assertArrayNotHasKey('no_getter', $result);
    }

    public function testToArrayConvertsObjectsWithGetIdToId(): void
    {
        $model = new TestModelWithEntity();
        $result = $model->toArray();

        // The entity object should be converted to its ID (42)
        self::assertArrayHasKey('entity', $result);
        self::assertSame(42, $result['entity']);
    }

    public function testToArrayConvertsEnumsToBackingValue(): void
    {
        $model = new TestModelWithEnum();
        $result = $model->toArray();

        // The enum should be converted to its backing value
        self::assertArrayHasKey('status', $result);
        self::assertSame('active', $result['status']);
    }

    public function testToArrayProvidesBothCamelCaseAndSnakeCase(): void
    {
        $model = new TestModelWithCamelCase();
        $result = $model->toArray();

        // Both camelCase and snake_case versions should exist
        self::assertArrayHasKey('userName', $result);
        self::assertArrayHasKey('user_name', $result);
        self::assertArrayHasKey('emailAddress', $result);
        self::assertArrayHasKey('email_address', $result);

        // Values should be the same
        self::assertSame($result['userName'], $result['user_name']);
        self::assertSame($result['emailAddress'], $result['email_address']);
    }
}
