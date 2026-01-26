<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Entity\Team;
use App\Entity\User;
use App\Enum\UserType;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Security\LdapAuthenticator;
use App\Service\Ldap\LdapClientService;
use Doctrine\ORM\EntityManagerInterface;
use Laminas\Ldap\Exception\LdapException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Tests\Fixtures\TokenStub;

/**
 * Unit tests for LdapAuthenticator.
 *
 * @internal
 */
#[CoversClass(LdapAuthenticator::class)]
final class LdapAuthenticatorTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private RouterInterface&MockObject $router;
    private ParameterBagInterface&MockObject $parameterBag;
    private LoggerInterface&MockObject $logger;
    private LdapClientService&MockObject $ldapClient;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->router = $this->createMock(RouterInterface::class);
        $this->parameterBag = $this->createMock(ParameterBagInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->ldapClient = $this->createMock(LdapClientService::class);
    }

    private function makeSubject(): LdapAuthenticator
    {
        $this->router->method('generate')->willReturn('/');

        return new LdapAuthenticator(
            $this->entityManager,
            $this->router,
            $this->parameterBag,
            $this->logger,
            $this->ldapClient,
        );
    }

    private function configureDefaultLdapParams(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.example.com'],
            ['ldap_port', 389],
            ['ldap_readuser', 'cn=reader'],
            ['ldap_readpass', 'readerpass'],
            ['ldap_basedn', 'dc=example,dc=com'],
            ['ldap_usessl', false],
            ['ldap_usernamefield', 'sAMAccountName'],
            ['ldap_create_user', true],
        ]);
    }

    // ==================== supports() tests ====================

    public function testSupportsReturnsFalseForGetRequest(): void
    {
        $authenticator = $this->makeSubject();
        $request = new Request([], [], ['_route' => '_login']);
        $request->setMethod('GET');

        self::assertFalse($authenticator->supports($request));
    }

    public function testSupportsReturnsTrueForPostLoginRoute(): void
    {
        $authenticator = $this->makeSubject();
        $request = new Request([], [], ['_route' => '_login']);
        $request->setMethod('POST');

        self::assertTrue($authenticator->supports($request));
    }

    public function testSupportsReturnsTrueForLegacyLoginCheckRoute(): void
    {
        $authenticator = $this->makeSubject();
        $request = new Request([], [], ['_route' => 'login_check']);
        $request->setMethod('POST');

        self::assertTrue($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseForOtherRoutes(): void
    {
        $authenticator = $this->makeSubject();
        $request = new Request([], [], ['_route' => '_start']);
        $request->setMethod('POST');

        self::assertFalse($authenticator->supports($request));
    }

    public function testSupportsReturnsFalseForNullRoute(): void
    {
        $authenticator = $this->makeSubject();
        $request = new Request();
        $request->setMethod('POST');

        self::assertFalse($authenticator->supports($request));
    }

    // ==================== authenticate() validation tests ====================

    public function testAuthenticateThrowsExceptionForEmptyUsername(): void
    {
        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => '', '_password' => 'pass']);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionForEmptyPassword(): void
    {
        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => 'user', '_password' => '']);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionForBothEmpty(): void
    {
        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => '', '_password' => '']);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Username and password cannot be empty.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionForInvalidUsernameFormat(): void
    {
        $authenticator = $this->makeSubject();
        // Username with invalid characters
        $request = new Request([], ['_username' => 'user<script>', '_password' => 'pass']);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid username format.');

        $authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionForTooLongUsername(): void
    {
        $authenticator = $this->makeSubject();
        // Username longer than 256 characters
        $longUsername = str_repeat('a', 257);
        $request = new Request([], ['_username' => $longUsername, '_password' => 'pass']);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Invalid username format.');

        $authenticator->authenticate($request);
    }

    // ==================== authenticate() LDAP tests ====================

    public function testAuthenticateReturnsExistingUser(): void
    {
        $this->configureDefaultLdapParams();

        $existingUser = (new User())->setUsername('testuser');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn($existingUser);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepo);

        // Configure LDAP client to allow chaining
        $this->ldapClient->method('setHost')->willReturnSelf();
        $this->ldapClient->method('setPort')->willReturnSelf();
        $this->ldapClient->method('setReadUser')->willReturnSelf();
        $this->ldapClient->method('setReadPass')->willReturnSelf();
        $this->ldapClient->method('setBaseDn')->willReturnSelf();
        $this->ldapClient->method('setUserName')->willReturnSelf();
        $this->ldapClient->method('setUserPass')->willReturnSelf();
        $this->ldapClient->method('setUseSSL')->willReturnSelf();
        $this->ldapClient->method('setUserNameField')->willReturnSelf();
        $this->ldapClient->method('login')->willReturn(true);

        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => 'testuser', '_password' => 'pass123', '_csrf_token' => 'token']);

        $passport = $authenticator->authenticate($request);

        // Trigger the user loader
        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);
        self::assertNotNull($userBadge);

        $user = $userBadge->getUser();
        self::assertSame($existingUser, $user);
    }

    public function testAuthenticateCreatesNewUserWhenAllowed(): void
    {
        $this->configureDefaultLdapParams();

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(static fn (string $class) => match ($class) {
                User::class => $userRepo,
                default => null,
            });

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $this->ldapClient->method('setHost')->willReturnSelf();
        $this->ldapClient->method('setPort')->willReturnSelf();
        $this->ldapClient->method('setReadUser')->willReturnSelf();
        $this->ldapClient->method('setReadPass')->willReturnSelf();
        $this->ldapClient->method('setBaseDn')->willReturnSelf();
        $this->ldapClient->method('setUserName')->willReturnSelf();
        $this->ldapClient->method('setUserPass')->willReturnSelf();
        $this->ldapClient->method('setUseSSL')->willReturnSelf();
        $this->ldapClient->method('setUserNameField')->willReturnSelf();
        $this->ldapClient->method('login')->willReturn(true);
        $this->ldapClient->method('getTeams')->willReturn([]);

        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => 'newuser', '_password' => 'pass123', '_csrf_token' => 'token']);

        $passport = $authenticator->authenticate($request);
        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);
        self::assertNotNull($userBadge);

        $user = $userBadge->getUser();
        self::assertInstanceOf(User::class, $user);
        self::assertSame('newuser', $user->getUsername());
        self::assertSame(UserType::DEV, $user->getType());
        self::assertSame('de', $user->getLocale());
    }

    public function testAuthenticateCreatesUserWithTeams(): void
    {
        $this->configureDefaultLdapParams();

        $team = (new Team())->setName('Dev Team');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $teamRepo = $this->createMock(TeamRepository::class);
        $teamRepo->method('findOneBy')->willReturn($team);

        $this->entityManager->method('getRepository')
            ->willReturnCallback(static fn (string $class) => match ($class) {
                User::class => $userRepo,
                Team::class => $teamRepo,
                default => null,
            });

        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');

        $this->ldapClient->method('setHost')->willReturnSelf();
        $this->ldapClient->method('setPort')->willReturnSelf();
        $this->ldapClient->method('setReadUser')->willReturnSelf();
        $this->ldapClient->method('setReadPass')->willReturnSelf();
        $this->ldapClient->method('setBaseDn')->willReturnSelf();
        $this->ldapClient->method('setUserName')->willReturnSelf();
        $this->ldapClient->method('setUserPass')->willReturnSelf();
        $this->ldapClient->method('setUseSSL')->willReturnSelf();
        $this->ldapClient->method('setUserNameField')->willReturnSelf();
        $this->ldapClient->method('login')->willReturn(true);
        $this->ldapClient->method('getTeams')->willReturn(['Dev Team']);

        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => 'teamuser', '_password' => 'pass123', '_csrf_token' => 'token']);

        $passport = $authenticator->authenticate($request);
        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);
        self::assertNotNull($userBadge);

        $user = $userBadge->getUser();
        self::assertInstanceOf(User::class, $user);
    }

    public function testAuthenticateThrowsUserNotFoundWhenCreationDisabled(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.example.com'],
            ['ldap_port', 389],
            ['ldap_readuser', 'cn=reader'],
            ['ldap_readpass', 'readerpass'],
            ['ldap_basedn', 'dc=example,dc=com'],
            ['ldap_usessl', false],
            ['ldap_usernamefield', 'sAMAccountName'],
            ['ldap_create_user', false], // Creation disabled
        ]);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn(null);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepo);

        $this->ldapClient->method('setHost')->willReturnSelf();
        $this->ldapClient->method('setPort')->willReturnSelf();
        $this->ldapClient->method('setReadUser')->willReturnSelf();
        $this->ldapClient->method('setReadPass')->willReturnSelf();
        $this->ldapClient->method('setBaseDn')->willReturnSelf();
        $this->ldapClient->method('setUserName')->willReturnSelf();
        $this->ldapClient->method('setUserPass')->willReturnSelf();
        $this->ldapClient->method('setUseSSL')->willReturnSelf();
        $this->ldapClient->method('setUserNameField')->willReturnSelf();
        $this->ldapClient->method('login')->willReturn(true);

        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => 'newuser', '_password' => 'pass123', '_csrf_token' => 'token']);

        $passport = $authenticator->authenticate($request);
        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);

        $this->expectException(UserNotFoundException::class);
        self::assertNotNull($userBadge);
        $userBadge->getUser();
    }

    public function testAuthenticateHandlesLdapException(): void
    {
        $this->configureDefaultLdapParams();

        $this->ldapClient->method('setHost')->willReturnSelf();
        $this->ldapClient->method('setPort')->willReturnSelf();
        $this->ldapClient->method('setReadUser')->willReturnSelf();
        $this->ldapClient->method('setReadPass')->willReturnSelf();
        $this->ldapClient->method('setBaseDn')->willReturnSelf();
        $this->ldapClient->method('setUserName')->willReturnSelf();
        $this->ldapClient->method('setUserPass')->willReturnSelf();
        $this->ldapClient->method('setUseSSL')->willReturnSelf();
        $this->ldapClient->method('setUserNameField')->willReturnSelf();
        // LdapException constructor requires Ldap|null as first arg
        $this->ldapClient->method('login')->willThrowException(new LdapException(null, 'LDAP connection failed'));

        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => 'testuser', '_password' => 'wrongpass', '_csrf_token' => 'token']);

        $passport = $authenticator->authenticate($request);
        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Authentication failed. Please check your credentials.');

        self::assertNotNull($userBadge);
        $userBadge->getUser();
    }

    public function testAuthenticateHandlesUnexpectedException(): void
    {
        $this->configureDefaultLdapParams();

        $this->ldapClient->method('setHost')->willReturnSelf();
        $this->ldapClient->method('setPort')->willReturnSelf();
        $this->ldapClient->method('setReadUser')->willReturnSelf();
        $this->ldapClient->method('setReadPass')->willReturnSelf();
        $this->ldapClient->method('setBaseDn')->willReturnSelf();
        $this->ldapClient->method('setUserName')->willReturnSelf();
        $this->ldapClient->method('setUserPass')->willReturnSelf();
        $this->ldapClient->method('setUseSSL')->willReturnSelf();
        $this->ldapClient->method('setUserNameField')->willReturnSelf();
        $this->ldapClient->method('login')->willThrowException(new RuntimeException('Unexpected error'));

        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => 'testuser', '_password' => 'pass', '_csrf_token' => 'token']);

        $passport = $authenticator->authenticate($request);
        $userBadge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);

        $this->expectException(CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('An unexpected error occurred during authentication.');

        self::assertNotNull($userBadge);
        $userBadge->getUser();
    }

    // ==================== onAuthenticationSuccess() tests ====================

    public function testOnAuthenticationSuccessRedirectsToTargetPath(): void
    {
        $this->router->method('generate')->willReturn('/_start');

        $authenticator = $this->makeSubject();

        $session = new Session(new MockArraySessionStorage());
        $session->set('_security.main.target_path', '/dashboard');

        $request = new Request();
        $request->setSession($session);

        $user = $this->createMock(User::class);
        $user->method('getUsername')->willReturn('testuser');

        $token = new TokenStub($user);

        $response = $authenticator->onAuthenticationSuccess($request, $token, 'main');

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/dashboard', $response->headers->get('Location'));
    }

    public function testOnAuthenticationSuccessRedirectsToStartWhenNoTarget(): void
    {
        $this->router->method('generate')
            ->with('_start')
            ->willReturn('/_start');

        $authenticator = $this->makeSubject();

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $user = $this->createMock(User::class);
        $user->method('getUsername')->willReturn('testuser');

        $token = new TokenStub($user);

        $response = $authenticator->onAuthenticationSuccess($request, $token, 'main');

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    // ==================== getLoginUrl() tests ====================

    public function testGetLoginUrlReturnsLoginRoute(): void
    {
        $this->router->method('generate')
            ->willReturnCallback(static fn (string $route) => match ($route) {
                '_login' => '/login',
                default => '/',
            });

        $authenticator = $this->makeSubject();

        $reflection = new ReflectionClass($authenticator);
        $method = $reflection->getMethod('getLoginUrl');

        $request = new Request();
        $result = $method->invoke($authenticator, $request);

        self::assertSame('/login', $result);
    }

    // ==================== parsePort() tests ====================

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function parsePortProvider(): iterable
    {
        yield 'integer value' => [389, 389];
        yield 'string value' => ['636', 636];
        yield 'null value' => [null, 0];
        yield 'array value' => [[], 0];
        yield 'float value' => [389.5, 0];
        yield 'backed enum value' => [\App\Enum\TicketSystemType::JIRA, 0]; // JIRA has string value 'JIRA'
    }

    #[DataProvider('parsePortProvider')]
    public function testParsePort(mixed $input, int $expected): void
    {
        $authenticator = $this->makeSubject();

        $reflection = new ReflectionClass($authenticator);
        $method = $reflection->getMethod('parsePort');

        $result = $method->invoke($authenticator, $input);

        self::assertSame($expected, $result);
    }

    // ==================== sanitizeLdapInput() tests ====================

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sanitizeLdapInputProvider(): iterable
    {
        yield 'normal string' => ['username', 'username'];
        yield 'backslash' => ['user\\name', 'user\5cname'];
        yield 'asterisk' => ['user*name', 'user\2aname'];
        yield 'parentheses' => ['user(name)', 'user\28name\29'];
        yield 'null byte' => ["user\x00name", 'user\00name'];
        yield 'forward slash' => ['user/name', 'user\2fname'];
        yield 'multiple special' => ['a\\b*c(d)e', 'a\5cb\2ac\28d\29e'];
    }

    #[DataProvider('sanitizeLdapInputProvider')]
    public function testSanitizeLdapInput(string $input, string $expected): void
    {
        $authenticator = $this->makeSubject();

        $reflection = new ReflectionClass($authenticator);
        $method = $reflection->getMethod('sanitizeLdapInput');

        $result = $method->invoke($authenticator, $input);

        self::assertSame($expected, $result);
    }

    // ==================== isValidUsername() tests ====================

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function isValidUsernameProvider(): iterable
    {
        yield 'simple username' => ['john', true];
        yield 'with dot' => ['john.doe', true];
        yield 'with hyphen' => ['john-doe', true];
        yield 'with underscore' => ['john_doe', true];
        yield 'with at' => ['john@example.com', true];
        yield 'alphanumeric' => ['user123', true];
        yield 'empty string' => ['', false];
        yield 'with space' => ['john doe', false];
        yield 'with angle brackets' => ['john<doe>', false];
        yield 'with semicolon' => ['john;doe', false];
        yield 'with parentheses' => ['john(doe)', false];
        yield 'max length 256' => [str_repeat('a', 256), true];
        yield 'too long 257' => [str_repeat('a', 257), false];
    }

    #[DataProvider('isValidUsernameProvider')]
    public function testIsValidUsername(string $username, bool $expected): void
    {
        $authenticator = $this->makeSubject();

        $reflection = new ReflectionClass($authenticator);
        $method = $reflection->getMethod('isValidUsername');

        $result = $method->invoke($authenticator, $username);

        self::assertSame($expected, $result);
    }

    // ==================== Edge cases ====================

    public function testAuthenticateWithValidEmailUsername(): void
    {
        $this->configureDefaultLdapParams();

        $existingUser = (new User())->setUsername('user@example.com');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findOneBy')->willReturn($existingUser);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepo);

        $this->ldapClient->method('setHost')->willReturnSelf();
        $this->ldapClient->method('setPort')->willReturnSelf();
        $this->ldapClient->method('setReadUser')->willReturnSelf();
        $this->ldapClient->method('setReadPass')->willReturnSelf();
        $this->ldapClient->method('setBaseDn')->willReturnSelf();
        $this->ldapClient->method('setUserName')->willReturnSelf();
        $this->ldapClient->method('setUserPass')->willReturnSelf();
        $this->ldapClient->method('setUseSSL')->willReturnSelf();
        $this->ldapClient->method('setUserNameField')->willReturnSelf();
        $this->ldapClient->method('login')->willReturn(true);

        $authenticator = $this->makeSubject();
        $request = new Request([], ['_username' => 'user@example.com', '_password' => 'pass', '_csrf_token' => 'token']);

        $passport = $authenticator->authenticate($request);

        self::assertNotNull($passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class));
        self::assertNotNull($passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge::class));
        self::assertNotNull($passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge::class));
    }
}
