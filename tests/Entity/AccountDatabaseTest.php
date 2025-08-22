<?php

namespace Tests\Entity;

use Tests\AbstractWebTestCase;
use App\Entity\Account;
use App\Entity\Entry;
use Doctrine\ORM\EntityManagerInterface;

class AccountDatabaseTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
    }

    public function testPersistAndFind(): void
    {
        // Create a new Account
        $account = new Account();
        $account->setName('Test Database Account');

        // Persist to database
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        // Get ID and clear entity manager to ensure fetch from DB
        $id = $account->getId();
        $this->assertNotNull($id, 'Account ID should not be null after persist');
        $this->entityManager->clear();

        // Fetch from database and verify
        $fetchedAccount = $this->entityManager->getRepository(Account::class)->find($id);
        $this->assertNotNull($fetchedAccount, 'Account was not found in database');
        $this->assertEquals('Test Database Account', $fetchedAccount->getName());

        // Clean up - remove the test entity
        $this->entityManager->remove($fetchedAccount);
        $this->entityManager->flush();
    }

    public function testUpdate(): void
    {
        // Create a new Account
        $account = new Account();
        $account->setName('Account To Update');

        // Persist to database
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $id = $account->getId();

        // Update account
        $account->setName('Updated Account');
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch and verify updates
        $updatedAccount = $this->entityManager->getRepository(Account::class)->find($id);
        $this->assertEquals('Updated Account', $updatedAccount->getName());

        // Clean up
        $this->entityManager->remove($updatedAccount);
        $this->entityManager->flush();
    }

    public function testDelete(): void
    {
        // Create a new Account
        $account = new Account();
        $account->setName('Account To Delete');

        // Persist to database
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $id = $account->getId();

        // Delete account
        $this->entityManager->remove($account);
        $this->entityManager->flush();

        // Verify account is deleted
        $deletedAccount = $this->entityManager->getRepository(Account::class)->find($id);
        $this->assertNull($deletedAccount, 'Account should be deleted from database');
    }

    public function testEntryRelationship(): void
    {
        // Create and persist account
        $account = new Account();
        $account->setName('Account With Entries');

        $this->entityManager->persist($account);

        // Create and add entries
        $entry1 = new Entry();
        $entry1->setAccount($account);
        $entry1->setDay('2023-01-01');
        $entry1->setStart('09:00');
        $entry1->setEnd('10:00');
        $entry1->setDuration(60);
        $entry1->setTicket('TEST-001');
        $entry1->setDescription('Test entry 1');
        $entry1->setClass(Entry::CLASS_PLAIN);

        $entry2 = new Entry();
        $entry2->setAccount($account);
        $entry2->setDay('2023-01-02');
        $entry2->setStart('14:00');
        $entry2->setEnd('16:00');
        $entry2->setDuration(120);
        $entry2->setTicket('TEST-002');
        $entry2->setDescription('Test entry 2');
        $entry2->setClass(Entry::CLASS_PLAIN);

        $this->entityManager->persist($entry1);
        $this->entityManager->persist($entry2);
        $this->entityManager->flush();

        $accountId = $account->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedAccount = $this->entityManager->find(Account::class, $accountId);

        // Test entry relationship
        $this->assertCount(2, $fetchedAccount->getEntries());
        $entries = $fetchedAccount->getEntries();
        $entryIds = [];
        foreach ($entries as $entry) {
            $entryIds[] = $entry->getId();
        }

        // Clean up
        foreach ($entries as $entry) {
            $this->entityManager->remove($entry);
        }

        $this->entityManager->flush();
        $this->entityManager->remove($fetchedAccount);
        $this->entityManager->flush();
    }

    public function testQueryMethodsInRepository(): void
    {
        // Create test accounts
        $account1 = new Account();
        $account1->setName('Account1');

        $account2 = new Account();
        $account2->setName('Account2');

        // Persist to database
        $this->entityManager->persist($account1);
        $this->entityManager->persist($account2);
        $this->entityManager->flush();

        // Test repository methods
        $entityRepository = $this->entityManager->getRepository(Account::class);

        // Test findAll
        $allAccounts = $entityRepository->findAll();
        $this->assertGreaterThanOrEqual(2, count($allAccounts));

        // Test findBy with criteria
        $matchingAccounts = $entityRepository->findBy(['name' => 'Account1']);
        $this->assertCount(1, $matchingAccounts);

        // Clean up
        $this->entityManager->remove($account1);
        $this->entityManager->remove($account2);
        $this->entityManager->flush();
    }
}
