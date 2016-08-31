<?php

namespace Netresearch\TimeTrackerBundle\Helper;

use appDevDebugProjectContainer;
use Doctrine\Bundle\DoctrineBundle\Registry;
use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\OAuthAwareUserProviderInterface;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Entity\UserTicketsystem;
use Symfony\Component\HttpFoundation\Session\Session;

class OAuthJiraUserProvider implements OAuthAwareUserProviderInterface
{
    /**
     * @var Session
     */
    protected $session;

    /**
     * @var Registry
     */
    protected $doctrine;

    /**
     * @var \Symfony\Component\DependencyInjection\Container
     */
    protected $service_container;

    /**
     * @var \Symfony\Bundle\FrameworkBundle\Routing\Router
     */
    protected $router;

    /**
     * @var string
     */
    protected $redirectTargetRoute;


    public function __construct(Session $session, Registry $doctrine,
                                \Symfony\Component\DependencyInjection\Container $service_container,
                                \Symfony\Bundle\FrameworkBundle\Routing\Router $router,
                                $redirectTargetRoute)
    {
        $this->router = $router;
        $this->session = $session;
        $this->doctrine = $doctrine;
        $this->service_container = $service_container;
        $this->redirectTargetRoute = $redirectTargetRoute;
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        $jiraBaseUrl = $response->getResourceOwner()->getOption('base_url');
        $accessToken = $response->getAccessToken();
        $tokensecret = $response->getTokenSecret();

        $user_id = $this->session->get('loginId');

        /** @var $user User */
        $user = $this->doctrine->getRepository('NetresearchTimeTrackerBundle:User')->find($user_id);

        /** @var $ticketSystem TicketSystem */
        $ticketSystem = $this->doctrine->getRepository('NetresearchTimeTrackerBundle:Ticketsystem')->findOneBy([
            'url' => $jiraBaseUrl
        ]);

        if ($ticketSystem && $user) {
            /** @var $userTicketsystem UserTicketsystem */
            $userTicketsystem = $this->doctrine->getRepository('NetresearchTimeTrackerBundle:UserTicketsystem')->findOneBy([
                'user' => $user,
                'ticketSystem' => $ticketSystem,
            ]);

            if ($userTicketsystem) {
                $userTicketsystem->setAccessToken($accessToken)
                    ->setTokenSecret($tokensecret);
            } else {
                $userTicketsystem = new UserTicketsystem();
                $userTicketsystem->setUser($user)
                    ->setTicketSystem($ticketSystem)
                    ->setTokenSecret($tokensecret)
                    ->setAccessToken($accessToken)
                    ->setAvoidConnection(false);
            }

            $em = $this->doctrine->getManager();
            $em->persist($userTicketsystem);
            $em->flush();
        } else {
            // no fitting database entries found
        }

        // redirect to redirectTargetRoute instead of returning authenticated user
        $url = $this->router->generate($this->redirectTargetRoute);
        header('Location: '.$url);
        exit;
    }
}
