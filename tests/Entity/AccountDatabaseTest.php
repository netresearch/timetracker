<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Account;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\EntryClass;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Tests\AbstractWebTestCase;
use TypeError;

use function assert;
use function count;

/**
 * @internal
 *
 * @coversNothing
 */
final class AccountDatabaseTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        if (null === $this->serviceContainer) {
            throw new RuntimeException('Service container not initialized');
        }
        $entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
    }

    public function testPersistAndFind(): void
    {
        // Test data
        $accountData = [
            'name' => 'Test Account',
        ];

        // Create account
        $account = new Account();
        $account->setName($accountData['name']);

        // Persist and flush
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        // Verify ID was assigned
        self::assertNotNull($account->getId());

        // Find by ID
        $foundAccount = $this->entityManager->find(Account::class, $account->getId());
        self::assertNotNull($foundAccount);
        assert($foundAccount instanceof Account);
        self::assertSame($accountData['name'], $foundAccount->getName());

        // Test legacy method still works
        self::assertSame($accountData['name'], $foundAccount->getAccountName());
    }

    public function testAccountEntryRelationship(): void
    {
        // Create account
        $account = new Account();
        $account->setName('Test Account for Entry');

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        // Get user from database
        $userRepository = $this->entityManager->getRepository(User::class);
        $user = $userRepository->find(1);
        if (null === $user) {
            // Create a test user if it doesn't exist
            $user = new User();
            $user->setUsername('test_user');
            $user->setType('DEV');
            $user->setLocale('en');
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        // Create required entities for the entry
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $this->entityManager->persist($customer);

        $project = new Project();
        $project->setName('Test Project');
        $project->setCustomer($customer);
        $project->setActive(true);
        $this->entityManager->persist($project);

        $activity = new Activity();
        $activity->setName('Development');
        $activity->setNeedsTicket(false);
        $activity->setFactor(1.0);
        $this->entityManager->persist($activity);

        // Create entry associated with account
        $entry = new Entry();
        $entry->setUser($user);
        $entry->setDay('2024-01-15');
        $entry->setStart('09:00:00');
        $entry->setEnd('10:00:00');
        $entry->setDuration(60);
        $entry->setDescription('Test entry for account');
        $entry->setClass(EntryClass::PLAIN);
        $entry->setCustomer($customer);
        $entry->setProject($project);
        $entry->setActivity($activity);
        $entry->setAccount($account);
        $account->addEntry($entry); // Establish bidirectional relationship

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        // Verify relationship
        $entryAccount = $entry->getAccount();
        self::assertNotNull($entryAccount);
        self::assertSame($account->getId(), $entryAccount->getId());

        // Test the relationship from account side
        $refreshedAccount = $this->entityManager->find(Account::class, $account->getId());
        self::assertNotNull($refreshedAccount);
        assert($refreshedAccount instanceof Account);
        $accountEntries = $refreshedAccount->getEntries();

        self::assertCount(1, $accountEntries);
        $firstEntry = $accountEntries->first();
        self::assertNotFalse($firstEntry);
        self::assertSame($entry->getId(), $firstEntry->getId());
    }

    public function testFindByName(): void
    {
        // Create test account
        $account = new Account();
        $account->setName('Name Test Account');

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        // Find by name
        $repository = $this->entityManager->getRepository(Account::class);
        $foundByName = $repository->findOneBy([
            'name' => 'Name Test Account',
        ]);

        self::assertNotNull($foundByName);
        assert($foundByName instanceof Account);
        self::assertSame('Name Test Account', $foundByName->getName());
    }

    public function testAccountValidation(): void
    {
        $account = new Account();

        // Test required fields - setName expects string, passing null should cause TypeError
        self::expectException(TypeError::class);
        /** @phpstan-ignore-next-line Intentionally passing null to test TypeError */
        $account->setName(null);
    }

    public function testMultipleAccountsCreation(): void
    {
        $accountsData = [
            ['name' => 'Account 1'],
            ['name' => 'Account 2'],
            ['name' => 'Account 3'],
        ];

        $createdAccounts = [];

        foreach ($accountsData as $data) {
            $account = new Account();
            $account->setName($data['name']);

            $this->entityManager->persist($account);
            $createdAccounts[] = $account;
        }

        $this->entityManager->flush();

        // Verify all accounts were created
        foreach ($createdAccounts as $account) {
            self::assertNotNull($account->getId());
        }

        // Verify count
        $repository = $this->entityManager->getRepository(Account::class);
        $queryBuilder = $repository->createQueryBuilder('a');
        $query = $queryBuilder
            ->select('COUNT(a.id)')
            ->getQuery();
        $totalCount = $query->getSingleScalarResult();

        self::assertGreaterThanOrEqual(count($accountsData), $totalCount);
    }
}
