<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Contract;
use App\Entity\Entry;
use App\Enum\EntryClass;
use App\Entity\Team;
use App\Entity\TicketSystem;
use App\Enum\TicketSystemType;
use App\Entity\User;
use App\Enum\UserType;
use App\Entity\UserTicketsystem;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class UserDatabaseTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    public function setUp(): void
    {
        parent::setUp();
        if ($this->serviceContainer === null) {
            throw new \RuntimeException('Service container not initialized');
        }
        $entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
        assert($entityManager instanceof EntityManagerInterface);
        $this->entityManager = $entityManager;
    }

    public function testPersistAndFind(): void
    {
        // Create a new User
        $user = new User();
        $user->setUsername('test_user');
        $user->setAbbr('TSU');
        $user->setType(UserType::DEV);
        $user->setLocale('de');
        $user->setShowEmptyLine(false);
        $user->setSuggestTime(true);
        $user->setShowFuture(true);

        // Persist to database
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Get ID and clear entity manager to ensure fetch from DB
        $id = $user->getId();
        self::assertNotNull($id, 'User ID should not be null after persist');
        $this->entityManager->clear();

        // Fetch from database and verify
        $fetchedUser = $this->entityManager->getRepository(User::class)->find($id);
        self::assertNotNull($fetchedUser, 'User was not found in database');
        self::assertInstanceOf(User::class, $fetchedUser);
        self::assertSame('test_user', $fetchedUser->getUsername());
        self::assertSame('TSU', $fetchedUser->getAbbr());
        self::assertSame(UserType::DEV, $fetchedUser->getType());
        self::assertSame('de', $fetchedUser->getLocale());
        self::assertFalse($fetchedUser->getShowEmptyLine());
        self::assertTrue($fetchedUser->getSuggestTime());
        self::assertTrue($fetchedUser->getShowFuture());

        // Clean up - remove the test entity
        $this->entityManager->remove($fetchedUser);
        $this->entityManager->flush();
    }

    public function testUpdate(): void
    {
        // Create a new User
        $user = new User();
        $user->setUsername('user_to_update');
        $user->setAbbr('UTU');
        $user->setType(UserType::DEV);
        $user->setLocale('de');
        $user->setShowEmptyLine(false);
        $user->setSuggestTime(true);
        $user->setShowFuture(true);

        // Persist to database
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $id = $user->getId();

        // Update user
        $user->setUsername('updated_user');
        $user->setAbbr('UPU');
        $user->setType(UserType::PL);
        $user->setLocale('en');
        $user->setShowEmptyLine(true);
        $user->setSuggestTime(false);
        $user->setShowFuture(false);

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch and verify updates
        $updatedUser = $this->entityManager->getRepository(User::class)->find($id);
        self::assertNotNull($updatedUser);
        self::assertInstanceOf(User::class, $updatedUser);
        self::assertSame('updated_user', $updatedUser->getUserIdentifier());
        self::assertSame('UPU', $updatedUser->getAbbr());
        self::assertSame(UserType::PL, $updatedUser->getType());
        self::assertSame('en', $updatedUser->getLocale());

        self::assertTrue($updatedUser->getShowEmptyLine());
        self::assertFalse($updatedUser->getSuggestTime());
        self::assertFalse($updatedUser->getShowFuture());

        // Clean up
        $this->entityManager->remove($updatedUser);
        $this->entityManager->flush();
    }

    public function testDelete(): void
    {
        // Create a new User
        $user = new User();
        $user->setUsername('user_to_delete');
        $user->setAbbr('UTD');
        $user->setType(UserType::DEV);
        $user->setLocale('de');
        $user->setShowEmptyLine(false);
        $user->setSuggestTime(true);
        $user->setShowFuture(true);

        // Persist to database
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $id = $user->getId();

        // Delete user
        $this->entityManager->remove($user);
        $this->entityManager->flush();

        // Verify user is deleted
        $deletedUser = $this->entityManager->getRepository(User::class)->find($id);
        self::assertNull($deletedUser, 'User should be deleted from database');
    }

    public function testTeamRelationship(): void
    {
        // Create user
        $user = new User();
        $user->setUsername('team_user');
        $user->setAbbr('TMU');
        $user->setType(UserType::DEV);
        $user->setLocale('de');
        $user->setShowEmptyLine(false);
        $user->setSuggestTime(true);
        $user->setShowFuture(true);

        $this->entityManager->persist($user);

        // Create teams and add the user to them
        $team1 = new Team();
        $team1->setName('Team 1');

        $this->entityManager->persist($team1);

        $team2 = new Team();
        $team2->setName('Team 2');

        $this->entityManager->persist($team2);

        // Add teams to user (user owns the relationship)
        $user->addTeam($team1);
        $user->addTeam($team2);

        $this->entityManager->flush();
        $userId = $user->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedUser = $this->entityManager->find(User::class, $userId);
        self::assertNotNull($fetchedUser, 'User should exist');

        // Test team relationship
        self::assertCount(2, $fetchedUser->getTeams());

        // Clean up
        $teams = $fetchedUser->getTeams()->toArray();
        $this->entityManager->remove($fetchedUser);
        $this->entityManager->flush();

        foreach ($teams as $team) {
            $this->entityManager->remove($team);
        }

        $this->entityManager->flush();
    }

    public function testContractRelationship(): void
    {
        // Create user
        $user = new User();
        $user->setUsername('contract_user');
        $user->setAbbr('CNU');
        $user->setType(UserType::DEV);
        $user->setLocale('de');
        $user->setShowEmptyLine(false);
        $user->setSuggestTime(true);
        $user->setShowFuture(true);

        $this->entityManager->persist($user);

        // Create contracts
        $contract1 = new Contract();
        $contract1->setUser($user);
        $contract1->setStart(new DateTime('2022-01-01'));
        $contract1->setEnd(new DateTime('2022-12-31'));
        $contract1->setHours0(0);
        $contract1->setHours1(8);
        $contract1->setHours2(8);
        $contract1->setHours3(8);
        $contract1->setHours4(8);
        $contract1->setHours5(4);
        $contract1->setHours6(0);

        $contract2 = new Contract();
        $contract2->setUser($user);
        $contract2->setStart(new DateTime('2023-01-01'));
        $contract2->setEnd(null);
        $contract2->setHours0(0);
        $contract2->setHours1(8);
        $contract2->setHours2(8);
        $contract2->setHours3(8);
        $contract2->setHours4(8);
        $contract2->setHours5(8);
        $contract2->setHours6(0);

        $this->entityManager->persist($contract1);
        $this->entityManager->persist($contract2);
        $this->entityManager->flush();

        $userId = $user->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedUser = $this->entityManager->find(User::class, $userId);
        self::assertNotNull($fetchedUser, 'User should exist');

        // Test contract relationship
        self::assertCount(2, $fetchedUser->getContracts());

        // Clean up
        $contracts = $fetchedUser->getContracts();
        foreach ($contracts as $contract) {
            $this->entityManager->remove($contract);
        }

        $this->entityManager->flush();

        $this->entityManager->remove($fetchedUser);
        $this->entityManager->flush();
    }

    public function testEntryRelationship(): void
    {
        // Create user
        $user = new User();
        $user->setUsername('entry_user');
        $user->setAbbr('ENU');
        $user->setType(UserType::DEV);
        $user->setLocale('de');
        $user->setShowEmptyLine(false);
        $user->setSuggestTime(true);
        $user->setShowFuture(true);

        $this->entityManager->persist($user);

        // Create entries
        $entry1 = new Entry();
        $entry1->setUser($user);
        $entry1->setDay('2023-01-01');
        $entry1->setStart('09:00');
        $entry1->setEnd('10:00');
        $entry1->setDuration(60);
        $entry1->setTicket('TEST-001');
        $entry1->setDescription('Test entry 1');
        $entry1->setClass(EntryClass::PLAIN);

        $entry2 = new Entry();
        $entry2->setUser($user);
        $entry2->setDay('2023-01-02');
        $entry2->setStart('14:00');
        $entry2->setEnd('16:00');
        $entry2->setDuration(120);
        $entry2->setTicket('TEST-002');
        $entry2->setDescription('Test entry 2');
        $entry2->setClass(EntryClass::PLAIN);

        $this->entityManager->persist($entry1);
        $this->entityManager->persist($entry2);
        $this->entityManager->flush();

        $userId = $user->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedUser = $this->entityManager->find(User::class, $userId);
        self::assertNotNull($fetchedUser, 'User should exist');

        // Test entry relationship
        self::assertCount(2, $fetchedUser->getEntries());

        // Clean up
        $entries = $fetchedUser->getEntries();
        foreach ($entries as $entry) {
            $this->entityManager->remove($entry);
        }

        $this->entityManager->flush();

        $this->entityManager->remove($fetchedUser);
        $this->entityManager->flush();
    }

    public function testTicketSystemRelationship(): void
    {
        // Create prerequisites
        // Create prerequisites
        $user = new User();
        $user->setUsername('ticketsystem_user');
        $user->setAbbr('TSU');
        $user->setType(UserType::DEV);
        $user->setLocale('de');
        $user->setShowEmptyLine(false);
        $user->setSuggestTime(true);
        $user->setShowFuture(true);

        $this->entityManager->persist($user);

        $ticketSystem = new TicketSystem();
        $ticketSystem->setName('Test Ticket System');
        $ticketSystem->setType(TicketSystemType::JIRA);
        $ticketSystem->setBookTime(true);
        $ticketSystem->setUrl('https://jira.example.com');
        $ticketSystem->setLogin('test_login');
        $ticketSystem->setPassword('test_password');
        $ticketSystem->setTicketUrl('https://jira.example.com/ticket/{ticket}');
        $ticketSystem->setPublicKey('test-public-key');
        $ticketSystem->setPrivateKey('test-private-key');
        $ticketSystem->setOauthConsumerKey('test-consumer-key');
        $ticketSystem->setOauthConsumerSecret('test-consumer-secret');

        $this->entityManager->persist($ticketSystem);

        // Create user-ticketsystem connection
        $userTicketSystem = new UserTicketsystem();
        $userTicketSystem->setUser($user);
        $userTicketSystem->setTicketSystem($ticketSystem);
        $userTicketSystem->setAccessToken('test-token');
        $userTicketSystem->setTokenSecret('test-secret');
        $userTicketSystem->setAvoidConnection(false);

        $this->entityManager->persist($userTicketSystem);
        $this->entityManager->flush();

        $userId = $user->getId();
        $ticketSystemId = $ticketSystem->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedUser = $this->entityManager->find(User::class, $userId);
        $fetchedTicketSystem = $this->entityManager->find(TicketSystem::class, $ticketSystemId);

        // Test ticket system relationship
        self::assertNotNull($fetchedUser, 'User should exist');
        self::assertCount(1, $fetchedUser->getUserTicketsystems());
        $userTs = $fetchedUser->getUserTicketsystems()->first();
        self::assertNotFalse($userTs, 'UserTicketsystem should exist');
        self::assertSame('test-token', $userTs->getAccessToken());
        self::assertNotNull($userTs->getTicketSystem(), 'TicketSystem should exist');
        self::assertSame('Test Ticket System', $userTs->getTicketSystem()->getName());

        // Clean up
        self::assertNotFalse($userTs, 'UserTicketsystem should not be false');
        $this->entityManager->remove($userTs);
        $this->entityManager->flush();

        // PHPStan knows $fetchedUser is never null after successful find() operation
        $this->entityManager->remove($fetchedUser);
        if ($fetchedTicketSystem instanceof TicketSystem) {
            $this->entityManager->remove($fetchedTicketSystem);
        }

        $this->entityManager->flush();
    }

    public function testRoles(): void
    {
        // Create a new User with DEV type
        $devUser = new User();
        $devUser->setUsername('dev_user');
        $devUser->setType(UserType::DEV);
        $devUser->setLocale('de');

        $this->entityManager->persist($devUser);

        // Create a new User with PL type
        $plUser = new User();
        $plUser->setUsername('pl_user');
        $plUser->setType(UserType::PL);
        $plUser->setLocale('de');

        $this->entityManager->persist($plUser);

        // Create a new User with ADMIN type
        $adminUser = new User();
        $adminUser->setUsername('admin_user');
        $adminUser->setType(UserType::ADMIN);
        $adminUser->setLocale('de');

        $this->entityManager->persist($adminUser);

        $this->entityManager->flush();

        // Check roles - The implementation only adds ROLE_USER and ROLE_ADMIN roles
        self::assertContains('ROLE_USER', $devUser->getRoles(), 'Missing ROLE_USER for dev');
        self::assertNotContains('ROLE_ADMIN', $devUser->getRoles());

        self::assertContains('ROLE_USER', $plUser->getRoles(), 'Missing ROLE_USER for pl');
        self::assertNotContains('ROLE_ADMIN', $plUser->getRoles());

        self::assertContains('ROLE_USER', $adminUser->getRoles(), 'Missing ROLE_USER for admin');
        self::assertContains('ROLE_ADMIN', $adminUser->getRoles(), 'Missing ROLE_ADMIN for admin');

        // Clean up
        $this->entityManager->remove($devUser);
        $this->entityManager->remove($plUser);
        $this->entityManager->remove($adminUser);
        $this->entityManager->flush();
    }
}