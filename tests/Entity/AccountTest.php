<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Account;
use App\Entity\Entry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Account entity.
 *
 * @internal
 */
#[CoversClass(Account::class)]
final class AccountTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorInitializesCollections(): void
    {
        $account = new Account();

        self::assertCount(0, $account->getEntries());
    }

    // ==================== ID tests ====================

    public function testIdIsNullByDefault(): void
    {
        $account = new Account();

        self::assertNull($account->getId());
    }

    public function testSetIdReturnsFluentInterface(): void
    {
        $account = new Account();

        $result = $account->setId(42);

        self::assertSame($account, $result);
        self::assertSame(42, $account->getId());
    }

    // ==================== Name tests ====================

    public function testNameIsEmptyByDefault(): void
    {
        $account = new Account();

        self::assertSame('', $account->getName());
    }

    public function testSetNameReturnsFluentInterface(): void
    {
        $account = new Account();

        $result = $account->setName('Development');

        self::assertSame($account, $result);
        self::assertSame('Development', $account->getName());
    }

    // ==================== Legacy AccountName methods tests ====================

    public function testGetAccountNameReturnsName(): void
    {
        $account = new Account();
        $account->setName('Testing');

        self::assertSame('Testing', $account->getAccountName());
    }

    public function testSetAccountNameSetsName(): void
    {
        $account = new Account();

        $result = $account->setAccountName('Support');

        self::assertSame($account, $result);
        self::assertSame('Support', $account->getName());
    }

    // ==================== Entries tests ====================

    public function testAddEntryReturnsFluentInterface(): void
    {
        $account = new Account();
        $entry = new Entry();

        $result = $account->addEntry($entry);

        self::assertSame($account, $result);
        self::assertCount(1, $account->getEntries());
    }

    public function testAddEntryDoesNotAddDuplicates(): void
    {
        $account = new Account();
        $entry = new Entry();

        $account->addEntry($entry);
        $account->addEntry($entry); // Same entry again

        self::assertCount(1, $account->getEntries());
    }

    public function testAddMultipleEntries(): void
    {
        $account = new Account();
        $entry1 = new Entry();
        $entry2 = new Entry();

        $account->addEntry($entry1);
        $account->addEntry($entry2);

        self::assertCount(2, $account->getEntries());
    }
}
