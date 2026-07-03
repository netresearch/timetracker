<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security;

use App\Entity\Team;
use App\Entity\User;
use App\Service\Ldap\LdapClientService;
use BackedEnum;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Laminas\Ldap\Exception\LdapException;
use Override;
use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Throwable;

use function in_array;
use function is_int;
use function is_scalar;
use function is_string;
use function sprintf;
use function strlen;

/**
 * The single login-form authenticator (ADR-018 D1). It routes per user:
 * a row with a local password hash is verified by the password hasher; any
 * other row (or a not-yet-provisioned username) goes through the unchanged
 * LDAP bind. LDAP is optional — an empty LDAP host switches the instance to
 * local-only mode and the LDAP branch is skipped entirely.
 */
class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    /** Lazily hashed dummy, reused to burn constant time on the no-such-account path. */
    private static ?string $dummyHash = null;

    public function __construct(private EntityManagerInterface $entityManager, private RouterInterface $router, private ParameterBagInterface $parameterBag, private LoggerInterface $logger, private LdapClientService $ldapClientService, private PasswordHasherFactoryInterface $passwordHasherFactory)
    {
    }

    #[Override]
    public function supports(Request $request): bool
    {
        // Support both unified _login route and legacy login_check route
        $route = $request->attributes->get('_route');
        $isLoginRoute = in_array($route, ['_login', 'login_check'], true);
        $isPostMethod = $request->isMethod('POST');

        // Only authenticate on POST requests to login routes
        $isLoginSubmit = $isLoginRoute && $isPostMethod;

        if ($isLoginSubmit) {
            $this->logger->debug('LdapAuthenticator: supports() returned true for POST on login route');
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

        $sharedBadges = [
            new CsrfTokenBadge('authenticate', $csrfToken),
            new RememberMeBadge(),
        ];

        // Local account (has a password hash): verify against the hash via the
        // password hasher. LDAP is never consulted for such a user.
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if ($existingUser instanceof User && $existingUser->isLocalAccount()) {
            return new Passport(
                new UserBadge($username, static fn (): User => $existingUser),
                new PasswordCredentials($password),
                $sharedBadges,
            );
        }

        // No local password → LDAP branch. With LDAP unconfigured (empty host)
        // there is no way to authenticate this identifier; fail generically so
        // local-only mode doesn't reveal whether the username exists.
        if (!$this->isLdapConfigured()) {
            // Burn the same time a real local wrong-password verify would, so
            // the response time can't tell "unknown user" from "real local user"
            // (login_throttling only limits repeats, not per-name timing probes).
            $this->equalizePasswordTiming($password);
            $this->logger->info('Login attempt against a non-local account while LDAP is not configured (local-only mode).');
            throw new CustomUserMessageAuthenticationException('Invalid credentials.');
        }

        // Store the current password temporarily for the user loader
        $this->currentPassword = $password;

        return new Passport(
            new UserBadge($username, fn (string $userIdentifier): User => $this->loadUser($userIdentifier)),
            new CustomCredentials(static fn (): true => true, ['username' => $username]),
            $sharedBadges,
        );
    }

    /**
     * Whether an LDAP host is configured. Empty host = local-only mode.
     */
    private function isLdapConfigured(): bool
    {
        $host = $this->parameterBag->get('ldap_host');

        return is_scalar($host) && '' !== (string) $host;
    }

    /**
     * Runs one password verify against a fixed dummy hash so the no-such-account
     * path takes the same time as a real wrong-password check (uses the same
     * configured hasher, so the algorithm/cost match). The dummy is hashed once
     * per process and reused.
     */
    private function equalizePasswordTiming(#[SensitiveParameter] string $password): void
    {
        $hasher = $this->passwordHasherFactory->getPasswordHasher(User::class);
        self::$dummyHash ??= $hasher->hash('timing-equalizer');
        $hasher->verify(self::$dummyHash, $password);
    }

    /**
     * Authenticates the user against LDAP and loads (or creates) the local user record.
     *
     * @throws CustomUserMessageAuthenticationException When LDAP authentication fails
     * @throws UserNotFoundException                    When the user is not found locally and creation is disabled
     */
    private function loadUser(string $userIdentifier): User
    {
        try {
            $this->configureLdapClient($userIdentifier)->login();

            $this->logger->info('LDAP authentication successful.', ['username' => $userIdentifier]);

            $user = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $userIdentifier]);
            if ($user instanceof User) {
                return $user;
            }

            if (!(bool) $this->parameterBag->get('ldap_create_user')) {
                throw new UserNotFoundException(sprintf('User "%s" authenticated via LDAP but not found locally and creation is disabled.', $userIdentifier));
            }

            return $this->createUserFromLdap($userIdentifier);
        } catch (LdapException $ldapException) {
            // Specific LDAP errors
            $this->logger->error('LDAP authentication error', [
                'username' => substr($userIdentifier, 0, 3) . '***',
                'error_code' => $ldapException->getCode(),
                'error_type' => $ldapException::class,
            ]);

            // Don't expose LDAP-specific errors to the user
            throw new CustomUserMessageAuthenticationException('Authentication failed. Please check your credentials.', [], $ldapException->getCode(), $ldapException);
        } catch (UserNotFoundException $userException) {
            // User not found and creation disabled
            $this->logger->info('User not found in local database', ['username' => substr($userIdentifier, 0, 3) . '***']);
            throw $userException;
        } catch (Throwable $throwable) {
            // Log class + message only — never the trace. A trace serializes
            // stack-frame arguments, which on this path can include the LDAP
            // bind password (the prod image ships no php.ini, so the built-in
            // zend.exception_ignore_args=Off default would capture them).
            $this->logger->error('Unexpected authentication error', [
                'username' => substr($userIdentifier, 0, 3) . '***',
                'error_type' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);
            throw new CustomUserMessageAuthenticationException('An unexpected error occurred during authentication.', [], $throwable->getCode(), $throwable);
        }
    }

    /**
     * Configures the LDAP client from container parameters.
     * Casts parameter bag values to the expected scalar types.
     */
    private function configureLdapClient(string $userIdentifier): LdapClientService
    {
        return $this->ldapClientService
            ->setHost($this->sanitizeLdapInput((string) (is_scalar($this->parameterBag->get('ldap_host')) ? $this->parameterBag->get('ldap_host') : '')))
            ->setPort($this->parsePort($this->parameterBag->get('ldap_port')))
            ->setReadUser($this->sanitizeLdapInput((string) (is_scalar($this->parameterBag->get('ldap_readuser')) ? $this->parameterBag->get('ldap_readuser') : '')))
            ->setReadPass((string) (is_scalar($this->parameterBag->get('ldap_readpass')) ? $this->parameterBag->get('ldap_readpass') : ''))
            ->setBaseDn($this->sanitizeLdapInput((string) (is_scalar($this->parameterBag->get('ldap_basedn')) ? $this->parameterBag->get('ldap_basedn') : '')))
            ->setUserName($this->sanitizeLdapInput($userIdentifier))
            ->setUserPass($this->currentPassword ?? '')
            ->setUseSSL((bool) ($this->parameterBag->get('ldap_usessl') ?? false))
            ->setUserNameField((string) (is_scalar($this->parameterBag->get('ldap_usernamefield')) ? $this->parameterBag->get('ldap_usernamefield') : ''));
    }

    /**
     * Creates a local user for an LDAP-authenticated identifier, mapping LDAP teams.
     */
    private function createUserFromLdap(string $userIdentifier): User
    {
        $newUser = new User()
            ->setUsername($userIdentifier)
            ->setType('DEV')
            ->setLocale('de');

        if ([] !== $this->ldapClientService->getTeams()) {
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
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        // Password accepted but a second factor is outstanding: scheb swapped the
        // token for a TwoFactorToken before this handler ran (ADR-018 D2). The SPA
        // needs the explicit signal — a bare {ok:true} would make it believe the
        // login completed. The no-JS fallback goes straight to the /2fa form.
        if ($token instanceof TwoFactorTokenInterface) {
            return $this->twoFactorRequiredResponse($request);
        }

        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);
        $redirect = null !== $targetPath && '' !== $targetPath
            ? $targetPath
            : $this->router->generate('_start');

        // The SolidJS login form submits via fetch (X-Requested-With) and expects
        // JSON so it can redirect without a full-page reload; the session and
        // remember-me cookies are still attached to this response by the firewall.
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true, 'redirect' => $redirect]);
        }

        return new RedirectResponse($redirect);
    }

    /** The "second factor outstanding" answer: JSON signal for the SPA, /2fa for no-JS. */
    private function twoFactorRequiredResponse(Request $request): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => false, 'twoFactorRequired' => true], Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse($this->router->generate('2fa_login'));
    }

    /**
     * For XHR logins return a 401 with a JSON body so the SolidJS form can show
     * an inline error; otherwise fall back to the default redirect-to-login flow
     * (which stores the error in the session for a server-rendered re-display).
     */
    #[Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => false, 'error' => $exception->getMessageKey()], Response::HTTP_UNAUTHORIZED);
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->router->generate('_login');
    }

    private ?string $currentPassword = null;

    private function parsePort(mixed $value): int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) => (int) $value,
            $value instanceof BackedEnum => (int) $value->value,
            default => 0,
        };
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
