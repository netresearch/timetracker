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

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
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
        self::assertNotNull($fetchedTeam, 'Team was not found in database');
        self::assertSame('Test Database Team', $fetchedTeam->getName());

        // Clean up - remove the test entity
        $this->entityManager->remove($fetchedTeam);
        $this->entityManager->flush();
    }

    public function testUpdate(): void
    {
        // Create a new Team
        $team = new Team();
        $team->setName('Team To Update');

        // Persist to database
        $this->entityManager->persist($team);
        $this->entityManager->flush();

        $id = $team->getId();

        // Update team
        $team->setName('Updated Team');
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch and verify updates
        $updatedTeam = $this->entityManager->getRepository(Team::class)->find($id);
        self::assertSame('Updated Team', $updatedTeam->getName());

        // Clean up
        $this->entityManager->remove($updatedTeam);
        $this->entityManager->flush();
    }

    public function testDelete(): void
    {
        // Create a new Team
        $team = new Team();
        $team->setName('Team To Delete');

        // Persist to database
        $this->entityManager->persist($team);
        $this->entityManager->flush();

        $id = $team->getId();

        // Delete team
        $this->entityManager->remove($team);
        $this->entityManager->flush();

        // Verify team is deleted
        $deletedTeam = $this->entityManager->getRepository(Team::class)->find($id);
        self::assertNull($deletedTeam, 'Team should be deleted from database');
    }

    public function testLeadUserRelationship(): void
    {
        // Create lead user
        $leadUser = new User();
        $leadUser->setUsername('lead_user');
        $leadUser->setType(UserType::PL);
        $leadUser->setLocale('de');

        $this->entityManager->persist($leadUser);

        // Create team with lead
        $team = new Team();
        $team->setName('Team With Lead');
        $team->setLeadUser($leadUser);

        $this->entityManager->persist($team);
        $this->entityManager->flush();

        $teamId = $team->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedTeam = $this->entityManager->find(Team::class, $teamId);

        // Test lead user relationship
        self::assertNotNull($fetchedTeam->getLeadUser());
        self::assertSame('lead_user', $fetchedTeam->getLeadUser()->getUsername());

        // Clean up
        $this->entityManager->remove($fetchedTeam);
        $this->entityManager->flush();

        $leadUser = $this->entityManager->find(User::class, $leadUser->getId());
        $this->entityManager->remove($leadUser);
        $this->entityManager->flush();
    }

    public function testUserRelationship(): void
    {
        // Create users
        $user1 = new User();
        $user1->setUsername('team_user1');
        $user1->setType(UserType::DEV);
        $user1->setLocale('de');

        $this->entityManager->persist($user1);

        $user2 = new User();
        $user2->setUsername('team_user2');
        $user2->setType(UserType::DEV);
        $user2->setLocale('de');

        $this->entityManager->persist($user2);

        // Create team
        $team = new Team();
        $team->setName('Team With Users');

        $this->entityManager->persist($team);

        // Add team to users (User owns the relationship)
        $user1->addTeam($team);
        $user2->addTeam($team);

        $this->entityManager->flush();
        $teamId = $team->getId();

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
        self::assertCount(2, $users);

        // Clean up
        $this->entityManager->remove($team);
        $this->entityManager->flush();

        $this->entityManager->remove($user1);
        $this->entityManager->remove($user2);
        $this->entityManager->flush();
    }

    public function testCustomerRelationship(): void
    {
        // Create customers
        $customer1 = new Customer();
        $customer1->setName('Team Customer 1');
        $customer1->setActive(true);
        $customer1->setGlobal(false);

        $this->entityManager->persist($customer1);

        $customer2 = new Customer();
        $customer2->setName('Team Customer 2');
        $customer2->setActive(true);
        $customer2->setGlobal(false);

        $this->entityManager->persist($customer2);

        // Create team
        $team = new Team();
        $team->setName('Team With Customers');

        $this->entityManager->persist($team);

        // Add team to customers (Customer owns the relationship)
        $customer1->addTeam($team);
        $customer2->addTeam($team);

        $this->entityManager->flush();
        $teamId = $team->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedTeam = $this->entityManager->find(Team::class, $teamId);

        // Test customer relationship
        self::assertCount(2, $fetchedTeam->getCustomers());

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
