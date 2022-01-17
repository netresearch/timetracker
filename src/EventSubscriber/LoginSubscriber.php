<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

class LoginSubscriber implements EventSubscriberInterface
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected UserRepository $userRepo,
        protected array $adminUsers = []
    ) {

    }

    /**
     * Apply additional roles to user and store/update user in local database.
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $authUser = $event->getUser();

        if ($authUser instanceof User) {
            $user = $authUser;
        } else {
            $user = $this->userRepo->findOneBy(['username' => $authUser->getUserIdentifier()])
                ?? new User;

            $user->setUsername($authUser->getUserIdentifier())
                ->setRoles($user->getRoles() + $authUser->getRoles());
        }

        if (in_array($user->getUserIdentifier(), $this->adminUsers)) {
            $user->setRoles($user->getRoles() + ['ROLE_ADMIN']);
        }

        // ToDo assign LDAP teams and roles
        #$this->setTeamsByLdapResponse($event);

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