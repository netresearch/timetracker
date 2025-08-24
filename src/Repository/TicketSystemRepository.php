<?php

namespace App\Repository;

use App\Entity\TicketSystem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class TicketSystemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketSystem::class);
    }
    
    /**
     * get all ticket systems.
     *
     * @throws \ReflectionException
     *
     * @return array<int, array{ticketSystem: array<string, mixed>}>
     */
    public function getAllTicketSystems(): array
    {
        /** @var TicketSystem[] $systems */
        $systems = $this->findBy([], ['name' => 'ASC']);

        $data = [];
        foreach ($systems as $system) {
            $data[] = ['ticketSystem' => $system->toArray()];
        }

        return $data;
    }

    public function findOneByName(string $name): ?TicketSystem
    {
        $result = $this->findOneBy(['name' => $name]);

        return $result instanceof TicketSystem ? $result : null;
    }
}
