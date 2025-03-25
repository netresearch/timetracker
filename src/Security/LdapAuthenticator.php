<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Team;
use App\Helper\LdapClient;
use App\Helper\LoginHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LdapAuthenticator extends AbstractFormLoginAuthenticator
{
    use TargetPathTrait;

    public function __construct(private EntityManagerInterface $entityManager, private RouterInterface $router, private ParameterBagInterface $parameterBag, private LoggerInterface $logger)
    {
    }

    public function supports(Request $request)
    {
        return ($request->attributes->get('_route') === '_login') &&
               $request->isMethod('POST');
    }

    public function getCredentials(Request $request)
    {
        return [
            'username' => $request->request->get('username'),
            'password' => $request->request->get('password'),
            'csrf_token' => $request->request->get('_csrf_token'),
            'remember_me' => $request->request->has('loginCookie'),
        ];
    }

    public function getUser($credentials, UserProviderInterface $userProvider)
    {
        $username = $credentials['username'];

        // Load user from database
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['username' => $username]);

        return $user;
    }

    public function checkCredentials($credentials, UserInterface $user)
    {
        try {
            $ldapClient = new LdapClient($this->logger);

            $ldapClient->setHost($this->parameterBag->get('ldap_host'))
                ->setPort($this->parameterBag->get('ldap_port'))
                ->setReadUser($this->parameterBag->get('ldap_readuser'))
                ->setReadPass($this->parameterBag->get('ldap_readpass'))
                ->setBaseDn($this->parameterBag->get('ldap_basedn'))
                ->setUserName($credentials['username'])
                ->setUserPass($credentials['password'])
                ->setUseSSL($this->parameterBag->get('ldap_usessl'))
                ->setUserNameField($this->parameterBag->get('ldap_usernamefield'))
                ->login();

            // We already have the user from getUser(), so this check is not needed
            // But we're keeping it for completeness
            if (!$user instanceof User) {
                if (!(boolean) $this->parameterBag->get('ldap_create_user')) {
                    throw new CustomUserMessageAuthenticationException('No equivalent timetracker user could be found.');
                }

                // Create new user if users.username doesn't exist for valid ldap-authentication
                $user = new User();
                $user->setUsername($credentials['username'])
                    ->setType('DEV')
                    ->setShowEmptyLine('0')
                    ->setSuggestTime('1')
                    ->setShowFuture('1')
                    ->setLocale('de');

                if (!empty($ldapClient->getTeams())) {
                    $teamRepo = $this->entityManager->getRepository(Team::class);

                    foreach ($ldapClient->getTeams() as $teamname) {
                        $team = $teamRepo->findOneBy([
                            'name' => $teamname
                        ]);

                        if ($team) {
                            $user->addTeam($team);
                        }
                    }
                }

                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }

            return true;
        } catch (\Exception $exception) {
            $this->logger->error('Login failed', [
                'username' => $credentials['username'],
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]);

            throw new CustomUserMessageAuthenticationException($exception->getMessage());
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $providerKey)) {
            return new RedirectResponse($targetPath);
        }

        // Remember me cookie is handled by Symfony's remember_me functionality
        // No need to set custom session variables as Symfony's security system manages authentication state

        return new RedirectResponse($this->router->generate('_start'));
    }

    protected function getLoginUrl()
    {
        return $this->router->generate('_login');
    }

    public function supportsRememberMe()
    {
        return true;
    }
}
