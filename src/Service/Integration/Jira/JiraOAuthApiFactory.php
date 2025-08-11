<?php

namespace App\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\RouterInterface;

class JiraOAuthApiFactory
{
    public function __construct(private readonly ManagerRegistry $managerRegistry, private readonly RouterInterface $router)
    {
    }

    public function create(User $user, TicketSystem $ticketSystem): JiraOAuthApiService
    {
        return new JiraOAuthApiService($user, $ticketSystem, $this->managerRegistry, $this->router);
    }
}
