<?php

namespace App\DataFixtures;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\User\Types;
use App\Repository\ActivityRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $manager->persist((new Activity)->setName(Activity::SICK));
        $manager->persist((new Activity)->setName(Activity::HOLIDAY));
        $manager->persist((new Activity)->setName('Work'));

        $user = (new User)
            ->setUsername('admin')
            ->setAbbr('ADM')
            ->setType(Types::PL)
            ->setRoles(['ROLE_PL']);
        $manager->persist($user);

        $team = (new Team)
            ->setName('Default')
            ->setLeadUser($user);
        $manager->persist($team);

        $customer = (new Customer)
            ->setName('ACME')
            ->setActive(true)
            ->setGlobal(true);
        $manager->persist($customer);

        $project = (new Project)
            ->setName('Website')
            ->setCustomer($customer)
            ->setGlobal(true)
            ->setActive(true);
        $manager->persist($project);

        $manager->flush();
    }
}
