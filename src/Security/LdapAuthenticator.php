<?php
declare(strict_types=1);

namespace App\Security;

use App\Entity\Team;
use App\Entity\User;
use App\Service\Ldap\LdapClientService;
use Doctrine\ORM\EntityManagerInterface;
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

    public function authenticate(Request $request): Passport
    {
        $username = (string) $request->request->get('_username');
        $password = (string) $request->request->get('_password');
        $csrfToken = (string) $request->request->get('_csrf_token');

        if ('' === $username || '' === $password) {
            throw new CustomUserMessageAuthenticationException('Username and password cannot be empty.');
        }

        $userLoader = function (string $userIdentifier): User {
            try {
                // --- Perform LDAP Authentication ---
                // Cast parameter bag values to expected scalar types
                $this->ldapClientService
                    ->setHost((string) (is_scalar($this->parameterBag->get('ldap_host')) ? $this->parameterBag->get('ldap_host') : ''))
                    ->setPort($this->parsePort($this->parameterBag->get('ldap_port')))
                    ->setReadUser((string) (is_scalar($this->parameterBag->get('ldap_readuser')) ? $this->parameterBag->get('ldap_readuser') : ''))
                    ->setReadPass((string) (is_scalar($this->parameterBag->get('ldap_readpass')) ? $this->parameterBag->get('ldap_readpass') : ''))
                    ->setBaseDn((string) (is_scalar($this->parameterBag->get('ldap_basedn')) ? $this->parameterBag->get('ldap_basedn') : ''))
                    ->setUserName($userIdentifier)
                    ->setUserPass($this->currentPassword ?? '')
                    ->setUseSSL((bool) ($this->parameterBag->get('ldap_usessl') ?? false))
                    ->setUserNameField((string) (is_scalar($this->parameterBag->get('ldap_usernamefield')) ? $this->parameterBag->get('ldap_usernamefield') : ''))
                    ->login();

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
                    ->setLocale('de');

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
            } catch (\Throwable $throwable) {
                $this->logger->warning('LDAP authentication failed.', ['username' => $userIdentifier, 'message' => $throwable->getMessage()]);
                throw new CustomUserMessageAuthenticationException('Invalid credentials.');
            }
        };

        // Store the current password temporarily for the user loader
        $this->currentPassword = $password;

        return new Passport(
            new UserBadge($username, $userLoader),
            new CustomCredentials(fn (): true => true, ['username' => $username]),
            [
                new CsrfTokenBadge('authenticate', $csrfToken),
                new RememberMeBadge(),
            ]
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
        if ($value instanceof \BackedEnum) {
            return (int) $value->value;
        }
        return 0;
    }
}
