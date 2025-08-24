<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Security\LdapAuthenticator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use App\Service\Ldap\LdapClientService;

class LdapAuthenticatorTest extends TestCase
{
    private function makeSubject(?ParameterBagInterface $parameterBag = null): LdapAuthenticator
    {
        /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $mock */
        $mock = $this->getMockBuilder(EntityManagerInterface::class)->disableOriginalConstructor()->getMock();
        /** @var RouterInterface&\PHPUnit\Framework\MockObject\MockObject $router */
        $router = $this->getMockBuilder(RouterInterface::class)->disableOriginalConstructor()->getMock();
        $router->method('generate')->willReturn('/');
        /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->getMockBuilder(LoggerInterface::class)->disableOriginalConstructor()->getMock();
        $parameterBag ??= $this->getMockBuilder(ParameterBagInterface::class)->disableOriginalConstructor()->getMock();
        /** @var LdapClientService&\PHPUnit\Framework\MockObject\MockObject $ldapClient */
        $ldapClient = $this->getMockBuilder(LdapClientService::class)->disableOriginalConstructor()->getMock();
        return new LdapAuthenticator($mock, $router, $parameterBag, $logger, $ldapClient);
    }

    public function testSupportsOnlyPostLogin(): void
    {
        $ldapAuthenticator = $this->makeSubject();
        $request = new Request([], [], ['_route' => '_login']);
        $request->setMethod('GET');
        $this->assertFalse($ldapAuthenticator->supports($request));
        $request->setMethod('POST');
        $this->assertTrue($ldapAuthenticator->supports($request));
    }

    public function testAuthenticateValidation(): void
    {
        $ldapAuthenticator = $this->makeSubject();
        $request = new Request(['_username' => '', '_password' => '']);
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $ldapAuthenticator->authenticate($request);
    }

    public function testOnAuthenticationSuccessRedirectsToStartWhenNoTarget(): void
    {
        $ldapAuthenticator = $this->makeSubject();
        $request = new Request();
        // Provide a session to avoid null in TargetPathTrait
        $session = new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
        $request->setSession($session);
        $mock = $this->getMockBuilder(\App\Entity\User::class)->disableOriginalConstructor()->getMock();
        $mock->method('getUsername')->willReturn('dev');
        $tokenStub = new \Tests\Fixtures\TokenStub($mock);
        $redirectResponse = $ldapAuthenticator->onAuthenticationSuccess($request, $tokenStub, 'main');
        $this->assertSame(\Symfony\Component\HttpFoundation\Response::HTTP_FOUND, $redirectResponse->getStatusCode(), $redirectResponse->getContent());
    }
}
