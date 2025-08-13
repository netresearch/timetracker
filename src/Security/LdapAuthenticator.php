<?php

namespace App\Security;

use App\Entity\User;
use App\Entity\Team;
use App\Service\Ldap\LdapClientService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LdapAuthenticator extends AbstractFormLoginAuthenticator
{
    use TargetPathTrait;

    public function __construct(private EntityManagerInterface $entityManager, private RouterInterface $router, private ParameterBagInterface $parameterBag, private LoggerInterface $logger, private LdapClientService $ldapClient)
    {
    }

    public function supports(Request $request)
    {
        $isLoginSubmit = ($request->attributes->get('_route') === '_login') && $request->isMethod('POST');
        if ($isLoginSubmit) {
            $this->logger->debug('LdapAuthenticator: supports() returned true for POST on _login');
        }
        return $isLoginSubmit;
    }

    public function getCredentials(Request $request)
    {
        $credentials = [
            'username' => $request->request->get('_username'),
            'password' => $request->request->get('_password'),
            'csrf_token' => $request->request->get('_csrf_token'),
            'remember_me' => $request->request->has('_remember_me'),
        ];
        $this->logger->debug('LdapAuthenticator: getCredentials() fetched.', ['username' => $credentials['username']]);

        if (empty($credentials['username']) || empty($credentials['password'])) {
            throw new CustomUserMessageAuthenticationException('Username and password cannot be empty.');
        }

        return $credentials;
    }

    /**
     * Performs LDAP Authentication and returns the User object (existing or newly created)
     * if successful, otherwise throws an exception.
     */
    public function getUser($credentials, UserProviderInterface $userProvider): UserInterface
    {
        $username = $credentials['username'];
        $this->logger->debug('LdapAuthenticator: getUser() called, attempting full LDAP auth.', ['username' => $username]);

        try {
            // --- Perform LDAP Authentication ---
            $ldapClient = $this->ldapClient;
            $ldapClient->setHost($this->parameterBag->get('ldap_host'))
                ->setPort($this->parameterBag->get('ldap_port'))
                ->setReadUser($this->parameterBag->get('ldap_readuser'))
                ->setReadPass($this->parameterBag->get('ldap_readpass'))
                ->setBaseDn($this->parameterBag->get('ldap_basedn'))
                ->setUserName($username)
                ->setUserPass($credentials['password'])
                ->setUseSSL($this->parameterBag->get('ldap_usessl'))
                ->setUserNameField($this->parameterBag->get('ldap_usernamefield'))
                ->login(); // This throws an exception on LDAP failure

            $this->logger->info('LDAP authentication successful within getUser.', ['username' => $username]);

            // --- LDAP Success: Find or Create Local User ---
            $user = $this->entityManager->getRepository(User::class)
                ->findOneBy(['username' => $username]);

            if ($user instanceof User) {
                $this->logger->info('Local user found after successful LDAP auth.', ['username' => $username, 'userId' => $user->getId()]);
                // Optional: Update local user details from LDAP if necessary here
                return $user;
            }

            // Local user not found, check if creation is allowed
            $this->logger->info('Local user not found after successful LDAP auth, checking creation policy.', ['username' => $username]);
            if (!(bool) $this->parameterBag->get('ldap_create_user')) {
                $this->logger->warning('LDAP auth successful, but user does not exist locally and ldap_create_user is false.', ['username' => $username]);
                // Throw the specific exception Guard expects when a user cannot be provided
                $ex = new UsernameNotFoundException(sprintf('User "%s" authenticated via LDAP but not found locally and creation is disabled.', $username));
                $ex->setUsername($username);
                throw $ex;
            }

            // Create new user
            $this->logger->info('Creating new local user after successful LDAP auth.', ['username' => $username]);
            $newUser = new User();
            $newUser->setUsername($username)
                 ->setType('DEV') // Consider making configurable
                 ->setLocale('de'); // Consider making configurable

            // Assign teams based on LDAP response
            if (!empty($ldapClient->getTeams())) {
                $teamRepo = $this->entityManager->getRepository(Team::class);
                foreach ($ldapClient->getTeams() as $teamname) {
                    $team = $teamRepo->findOneBy(['name' => $teamname]);
                    if ($team) {
                        $newUser->addTeam($team);
                        $this->logger->debug('Assigning team to new user.', ['username' => $username, 'team' => $teamname]);
                    } else {
                        $this->logger->warning('Team specified in LDAP mapping not found locally.', ['teamname' => $teamname]);
                    }
                }
            }

            $this->entityManager->persist($newUser);
            $this->entityManager->flush(); // Flush to get ID and ensure user exists for token
            $this->logger->info('New local user created and persisted.', ['username' => $username, 'userId' => $newUser->getId()]);

            return $newUser; // Return the newly created user

        } catch (UsernameNotFoundException $e) {
            // Re-throw exception if user creation was disabled
            $this->logger->warning('Re-throwing UsernameNotFoundException from getUser.', ['username' => $username, 'message' => $e->getMessage()]);
            throw $e;
        } catch (\Exception $exception) {
            // Log LDAP failures or other issues from LdapClient->login()
            $this->logger->warning('LDAP authentication failed within getUser.', [
                'username' => $username,
                'message' => $exception->getMessage(),
                // 'code' => $exception->getCode(), // Uncomment if useful
            ]);
            // Throw the exception expected by Guard for bad credentials
            throw new CustomUserMessageAuthenticationException('Invalid credentials.');
        }
    }

    /**
     * Credentials check is now handled entirely within getUser.
     * If this method is called, it means getUser successfully returned a User object.
     */
    public function checkCredentials($credentials, UserInterface $user): bool
    {
        $this->logger->debug('LdapAuthenticator: checkCredentials() called (authentication already verified by getUser).', ['username' => $user->getUsername()]);
        // No need to check password again, getUser handled it via LDAP binding.
        return true;
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, $providerKey): RedirectResponse
    {
        $user = $token->getUser();
        if ($user instanceof UserInterface) {
            $this->logger->info('LdapAuthenticator: onAuthenticationSuccess called.', ['username' => $user->getUsername()]);
        } else {
            $this->logger->info('LdapAuthenticator: onAuthenticationSuccess called.', ['username' => (string) $user]);
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $providerKey)) {
            $this->logger->debug('Redirecting to target path.', ['path' => $targetPath]);
            return new RedirectResponse($targetPath);
        }

        $startUrl = $this->router->generate('_start');
        $this->logger->debug('Redirecting to default path (' . $startUrl . ').', ['path' => $startUrl]);
        return new RedirectResponse($startUrl);
    }

    protected function getLoginUrl(): string
    {
        $loginUrl = $this->router->generate('_login');
        $this->logger->debug('LdapAuthenticator: getLoginUrl() called.', ['url' => $loginUrl]);
        return $loginUrl;
    }

    public function supportsRememberMe(): bool
    {
        return true;
    }
}
