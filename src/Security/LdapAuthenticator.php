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

    private $entityManager;
    private $router;
    private $params;
    private $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        RouterInterface $router,
        ParameterBagInterface $params,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->router = $router;
        $this->params = $params;
        $this->logger = $logger;
    }

    public function supports(Request $request)
    {
        // Support both the original login route and our new login_form route
        return ($request->attributes->get('_route') === '_login' ||
                $request->attributes->get('_route') === '_login_form') &&
               $request->isMethod('POST');
    }

    public function getCredentials(Request $request)
    {
        $credentials = [
            'username' => $request->request->get('username'),
            'password' => $request->request->get('password'),
            'csrf_token' => $request->request->get('_csrf_token'),
            'remember_me' => $request->request->has('loginCookie'),
        ];

        return $credentials;
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
            $client = new LdapClient($this->logger);

            $client->setHost($this->params->get('ldap_host'))
                ->setPort($this->params->get('ldap_port'))
                ->setReadUser($this->params->get('ldap_readuser'))
                ->setReadPass($this->params->get('ldap_readpass'))
                ->setBaseDn($this->params->get('ldap_basedn'))
                ->setUserName($credentials['username'])
                ->setUserPass($credentials['password'])
                ->setUseSSL($this->params->get('ldap_usessl'))
                ->setUserNameField($this->params->get('ldap_usernamefield'))
                ->login();

            // We already have the user from getUser(), so this check is not needed
            // But we're keeping it for completeness
            if (!$user instanceof User) {
                if (!(boolean) $this->params->get('ldap_create_user')) {
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

                if (!empty($client->getTeams())) {
                    $teamRepo = $this->entityManager->getRepository(Team::class);

                    foreach ($client->getTeams() as $teamname) {
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
        } catch (\Exception $e) {
            $this->logger->error('Login failed', [
                'username' => $credentials['username'],
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            throw new CustomUserMessageAuthenticationException($e->getMessage());
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey)
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $providerKey)) {
            return new RedirectResponse($targetPath);
        }

        // Set the credentials in the session
        $session = $request->getSession();
        /** @var User $user */
        $user = $token->getUser();
        $session->set('loggedIn', true); // Set the loggedIn flag expected by BaseController
        $session->set('loginTime', date('Y-m-d H:i:s'));
        $session->set('loginId', $user->getId());
        $session->set('loginName', $user->getUsername());
        $session->set('loginType', $user->getType());

        // Remember me cookie
        if ($request->request->has('loginCookie')) {
            // Call the helper to set a cookie
            LoginHelper::setCookie($user->getId(), $user->getUsername(), $this->params->get('secret'));
        }

        return new RedirectResponse($this->router->generate('_start'));
    }

    protected function getLoginUrl()
    {
        return $this->router->generate('_login_form');
    }

    public function supportsRememberMe()
    {
        return true;
    }
}
