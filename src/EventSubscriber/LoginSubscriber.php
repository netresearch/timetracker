<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Entity\User\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginSubscriber implements EventSubscriberInterface
{
    public function __construct(protected EntityManagerInterface $em)
    {

    }

    /**
     * Store user in local database.
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        /** @var User $user */
        $authUser = $event->getUser();

        // ToDo
        #$this->setTeamsByLdapResponse($event);

        $user  = $this->em->getRepository('App:User')->findOneBy(['username' => $authUser->getUserIdentifier()]);

        if ($user instanceof User) {
            return;
        }

        $user  = new User;
        $user->setUsername($authUser->getUserIdentifier());

        // ToDo: assign LDAP/AD groups to roles
        $user->setType(Types::DEV);

        $this->em->persist($user);
        $this->em->flush();
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    // /**
    //  * @param array $ldapResponse
    //  */
    // protected function setTeamsByLdapResponse(array $ldapResponse): void
    // {
    //     $dn          = $ldapResponse['dn'];
    //     $mappingFile = __DIR__.'/../../../../app/config/ldap_ou_team_mapping.yml';

    //     $this->teams = [];
    //     if (file_exists($mappingFile)) {
    //         $arMapping = Yaml::parse(file_get_contents($mappingFile));
    //         if (!$arMapping) {
    //             return;
    //         }

    //         foreach ($arMapping as $group => $teamName) {
    //             if (strpos($dn, 'ou='.$group)) {
    //                 $this->teams[] = $teamName;
    //             }
    //         }
    //     }
    // }
}