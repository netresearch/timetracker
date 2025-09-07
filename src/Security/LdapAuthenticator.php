<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Team;
use App\Entity\User;
use App\Service\Ldap\LdapClientService;
use BackedEnum;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Throwable;

use function is_int;
use function is_scalar;
use function is_string;
use function sprintf;
use function strlen;

class LdapAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(private EntityManagerInterface $entityManager, private RouterInterface $router, private ParameterBagInterface $parameterBag, private LoggerInterface $logger, private LdapClientService $ldapClientService)
    {
    }

    public function supports(Request $request): bool
    {
        $isLoginSubmit = ('_login' === $request->attributes->get('_route')) && $request->isMethod('POST');
        if ($isLoginSubmit) {
            $this->logger->debug('LdapAuthenticator: supports() returned true for POST on _login');
        }

        return $isLoginSubmit;
    }

    /**
     * @throws CustomUserMessageAuthenticationException When authentication fails
     * @throws UserNotFoundException                    When user is not found
     * @throws Exception                                When database operations fail
     * @throws Exception                                When LDAP or user creation operations fail
     */
    public function authenticate(Request $request): Passport
    {
        $username = $this->sanitizeLdapInput((string) $request->request->get('_username'));
        $password = (string) $request->request->get('_password');
        $csrfToken = (string) $request->request->get('_csrf_token');

        if ('' === $username || '' === $password) {
            throw new CustomUserMessageAuthenticationException('Username and password cannot be empty.');
        }

        // Validate username format
        if (!$this->isValidUsername($username)) {
            $this->logger->warning('Invalid username format attempted', ['username' => substr($username, 0, 3) . '***']);
            throw new CustomUserMessageAuthenticationException('Invalid username format.');
        }

        $userLoader = function (string $userIdentifier): User {
            try {
                // --- Perform LDAP Authentication ---
                // Cast parameter bag values to expected scalar types
                $this->ldapClientService
                    ->setHost($this->sanitizeLdapInput((string) (is_scalar($this->parameterBag->get('ldap_host')) ? $this->parameterBag->get('ldap_host') : '')))
                    ->setPort($this->parsePort($this->parameterBag->get('ldap_port')))
                    ->setReadUser($this->sanitizeLdapInput((string) (is_scalar($this->parameterBag->get('ldap_readuser')) ? $this->parameterBag->get('ldap_readuser') : '')))
                    ->setReadPass((string) (is_scalar($this->parameterBag->get('ldap_readpass')) ? $this->parameterBag->get('ldap_readpass') : ''))
                    ->setBaseDn($this->sanitizeLdapInput((string) (is_scalar($this->parameterBag->get('ldap_basedn')) ? $this->parameterBag->get('ldap_basedn') : '')))
                    ->setUserName($this->sanitizeLdapInput($userIdentifier))
                    ->setUserPass($this->currentPassword ?? '')
                    ->setUseSSL((bool) ($this->parameterBag->get('ldap_usessl') ?? false))
                    ->setUserNameField((string) (is_scalar($this->parameterBag->get('ldap_usernamefield')) ? $this->parameterBag->get('ldap_usernamefield') : ''))
                    ->login()
                ;

                $this->logger->info('LDAP authentication successful.', ['username' => $userIdentifier]);

                $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $userIdentifier]);
                if ($user instanceof User) {
                    return $user;
                }

                if (!(bool) $this->parameterBag->get('ldap_create_user')) {
                    throw new UserNotFoundException(sprintf('User "%s" authenticated via LDAP but not found locally and creation is disabled.', $userIdentifier));
                }

                $newUser = (new User())
                    ->setUsername($userIdentifier)
                    ->setType('DEV')
                    ->setLocale('de')
                ;

                if (!empty($this->ldapClientService->getTeams())) {
                    $teamRepo = $this->entityManager->getRepository(Team::class);
                    foreach ($this->ldapClientService->getTeams() as $teamname) {
                        $team = $teamRepo->findOneBy(['name' => $teamname]);
                        if ($team instanceof Team) {
                            $newUser->addTeam($team);
                        }
                    }
                }

                $this->entityManager->persist($newUser);
                $this->entityManager->flush();

                return $newUser;
            } catch (\Laminas\Ldap\Exception\LdapException $ldapException) {
                // Specific LDAP errors
                $this->logger->error('LDAP authentication error', [
                    'username' => substr($userIdentifier, 0, 3) . '***',
                    'error_code' => $ldapException->getCode(),
                    'error_type' => $ldapException::class,
                ]);

                // Don't expose LDAP-specific errors to the user
                throw new CustomUserMessageAuthenticationException('Authentication failed. Please check your credentials.');
            } catch (UserNotFoundException $userException) {
                // User not found and creation disabled
                $this->logger->info('User not found in local database', ['username' => substr($userIdentifier, 0, 3) . '***']);
                throw $userException;
            } catch (Throwable $throwable) {
                // Generic error handling
                $this->logger->error('Unexpected authentication error', [
                    'username' => substr($userIdentifier, 0, 3) . '***',
                    'error' => $throwable->getMessage(),
                    'trace' => $throwable->getTraceAsString(),
                ]);
                throw new CustomUserMessageAuthenticationException('An unexpected error occurred during authentication.');
            }
        };

        // Store the current password temporarily for the user loader
        $this->currentPassword = $password;

        return new Passport(
            new UserBadge($username, $userLoader),
            new CustomCredentials(static fn (): true => true, ['username' => $username]),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new RememberMeBadge(),
            ],
        );
    }

    public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): RedirectResponse
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->router->generate('_start'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->router->generate('_login');
    }

    private ?string $currentPassword = null;

    private function parsePort(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            return (int) $value;
        }

        if ($value instanceof BackedEnum) {
            return (int) $value->value;
        }

        return 0;
    }

    /**
     * Sanitizes LDAP input to prevent injection attacks
     * Escapes special characters according to RFC 4515.
     */
    private function sanitizeLdapInput(string $input): string
    {
        // LDAP special characters that need escaping
        $metaChars = [
            '\\' => '\5c',   // Must be first
            '*' => '\2a',
            '(' => '\28',
            ')' => '\29',
            "\x00" => '\00',
            '/' => '\2f',
        ];

        // Replace each special character with its escaped version
        return str_replace(
            array_keys($metaChars),
            array_values($metaChars),
            $input,
        );
    }

    /**
     * Validates username format to prevent injection attacks.
     */
    private function isValidUsername(string $username): bool
    {
        // Allow alphanumeric, dots, hyphens, underscores, and @ for email-style usernames
        // Max length 256 characters
        if (strlen($username) > 256) {
            return false;
        }

        // Check for basic valid characters
        return 1 === preg_match('/^[a-zA-Z0-9._@-]+$/', $username);
    }
}
