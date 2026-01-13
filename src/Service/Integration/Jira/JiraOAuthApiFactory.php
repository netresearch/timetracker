<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Service\Attribute\Required;

class JiraOAuthApiFactory
{
    public ManagerRegistry $managerRegistry;

    public RouterInterface $router;

    #[Required]
    public function setDependencies(ManagerRegistry $managerRegistry, RouterInterface $router): void
    {
        $this->managerRegistry = $managerRegistry;
        $this->router = $router;
    }

    public function create(User $user, TicketSystem $ticketSystem): JiraOAuthApiService
    {
        return new JiraOAuthApiService($user, $ticketSystem, $this->managerRegistry, $this->router);
    }
}
