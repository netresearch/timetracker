<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Customer;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class TeamDatabaseTest extends AbstractWebTestCase
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
        // Create a new Team
        $team = new Team();
        $team->setName('Test Database Team');

        // Persist to database
        $this->entityManager->persist($team);
        $this->entityManager->flush();

        // Get ID and clear entity manager to ensure fetch from DB
        $id = $team->getId();
        self::assertNotNull($id, 'Team ID should not be null after persist');
        $this->entityManager->clear();

        // Fetch from database and verify
        $fetchedTeam = $this->entityManager->getRepository(Team::class)->find($id);
        self::assertNotNull($fetchedTeam, 'Team should not be null');
        self::assertSame('Test Database Team', $fetchedTeam->getName());

        // Clean up
        $this->entityManager->remove($fetchedTeam);
        $this->entityManager->flush();
    }

    public function testTeamLeadUser(): void
    {
        // Create a lead user
        $leadUser = new User();
        $leadUser->setUsername('lead_user_test');
        $leadUser->setAbbr('LUT');
        $leadUser->setType(UserType::PL);
        $leadUser->setLocale('en');

        $this->entityManager->persist($leadUser);

        // Create a team with lead user
        $team = new Team();
        $team->setName('Team with Lead');
        $team->setLeadUser($leadUser);

        // Persist to database
        $this->entityManager->persist($team);
        $this->entityManager->flush();

        // Get ID and clear entity manager to ensure fetch from DB
        $teamId = $team->getId();
        $leadUserId = $leadUser->getId();
        self::assertNotNull($teamId, 'Team ID should not be null after persist');
        self::assertNotNull($leadUserId, 'Lead User ID should not be null after persist');
        $this->entityManager->clear();

        // Fetch from database and verify relationship
        $fetchedTeam = $this->entityManager->find(Team::class, $teamId);
        self::assertNotNull($fetchedTeam, 'Team should not be null');
        self::assertNotNull($fetchedTeam->getLeadUser(), 'Lead user should not be null');
        self::assertSame($leadUserId, $fetchedTeam->getLeadUser()->getId());
        self::assertSame('lead_user_test', $fetchedTeam->getLeadUser()->getUsername());

        // Clean up with null check to satisfy PHPStan
        $this->entityManager->remove($fetchedTeam);
        $leadUserFromTeam = $fetchedTeam->getLeadUser();
        if ($leadUserFromTeam !== null) {
            $this->entityManager->remove($leadUserFromTeam);
        }
        $this->entityManager->flush();
    }

    public function testUserRelationship(): void
    {
        // Create users
        $user1 = new User();
        $user1->setUsername('team_user_1');
        $user1->setAbbr('TU1');
        $user1->setType(UserType::DEV);
        $user1->setLocale('en');

        $user2 = new User();
        $user2->setUsername('team_user_2');
        $user2->setAbbr('TU2');
        $user2->setType(UserType::DEV);
        $user2->setLocale('en');

        $this->entityManager->persist($user1);
        $this->entityManager->persist($user2);

        // Create team
        $team = new Team();
        $team->setName('User Test Team');

        $this->entityManager->persist($team);

        // Associate users with team
        $user1->addTeam($team);
        $user2->addTeam($team);

        $this->entityManager->flush();
        $teamId = $team->getId();
        $user1Id = $user1->getId();
        $user2Id = $user2->getId();

        // Clear entity manager to ensure fetch from DB
        $this->entityManager->clear();

        // Get users for this team through custom repository method if available
        // For this test, we'll query users directly
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->join('u.teams', 't')
            ->where('t.id = :teamId')
            ->setParameter('teamId', $teamId)
            ->getQuery()
            ->getResult()
        ;

        // Test user relationship
        assert(is_countable($users));
        self::assertCount(2, $users);

        // Clean up - re-fetch entities since they were detached by clear()
        $teamToRemove = $this->entityManager->find(Team::class, $teamId);
        if (null !== $teamToRemove) {
            $this->entityManager->remove($teamToRemove);
        }
        $this->entityManager->flush();

        $user1ToRemove = $this->entityManager->find(User::class, $user1Id);
        $user2ToRemove = $this->entityManager->find(User::class, $user2Id);
        if (null !== $user1ToRemove) {
            $this->entityManager->remove($user1ToRemove);
        }
        if (null !== $user2ToRemove) {
            $this->entityManager->remove($user2ToRemove);
        }
        $this->entityManager->flush();
    }

    public function testCustomerRelationship(): void
    {
        // Create customers
        $customer1 = new Customer();
        $customer1->setName('Team Customer 1');
        $customer1->setActive(true);
        $customer1->setGlobal(false);

        $customer2 = new Customer();
        $customer2->setName('Team Customer 2');
        $customer2->setActive(true);
        $customer2->setGlobal(false);

        // Create team
        $team = new Team();
        $team->setName('Customer Test Team');

        $this->entityManager->persist($team);
        $this->entityManager->persist($customer1);
        $this->entityManager->persist($customer2);

        // Associate customers with team
        $customer1->addTeam($team);
        $customer2->addTeam($team);

        $this->entityManager->flush();
        $teamId = $team->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedTeam = $this->entityManager->find(Team::class, $teamId);
        self::assertNotNull($fetchedTeam, 'Team should not be null');

        // Test customer relationship
        $customers = $fetchedTeam->getCustomers();
        assert(is_countable($customers));
        self::assertCount(2, $customers);

        // Clean up
        $customers = $fetchedTeam->getCustomers()->toArray();
        foreach ($customers as $customer) {
            $this->entityManager->remove($customer);
        }

        $this->entityManager->flush();

        $this->entityManager->remove($fetchedTeam);
        $this->entityManager->flush();
    }
}