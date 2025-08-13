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
    private function makeSubject(ParameterBagInterface $params = null): LdapAuthenticator
    {
        /** @var EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject $em */
        $em = $this->getMockBuilder(EntityManagerInterface::class)->disableOriginalConstructor()->getMock();
        /** @var RouterInterface&\PHPUnit\Framework\MockObject\MockObject $router */
        $router = $this->getMockBuilder(RouterInterface::class)->disableOriginalConstructor()->getMock();
        $router->method('generate')->willReturn('/');
        /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject $logger */
        $logger = $this->getMockBuilder(LoggerInterface::class)->disableOriginalConstructor()->getMock();
        /** @var ParameterBagInterface&\PHPUnit\Framework\MockObject\MockObject $params */
        $params = $params ?? $this->getMockBuilder(ParameterBagInterface::class)->disableOriginalConstructor()->getMock();
        /** @var LdapClientService&\PHPUnit\Framework\MockObject\MockObject $ldapClient */
        $ldapClient = $this->getMockBuilder(LdapClientService::class)->disableOriginalConstructor()->getMock();
        return new LdapAuthenticator($em, $router, $params, $logger, $ldapClient);
    }

    public function testSupportsOnlyPostLogin(): void
    {
        $auth = $this->makeSubject();
        $r = new Request([], [], ['_route' => '_login']);
        $r->setMethod('GET');
        $this->assertFalse($auth->supports($r));
        $r->setMethod('POST');
        $this->assertTrue($auth->supports($r));
    }

    public function testGetCredentialsValidation(): void
    {
        $auth = $this->makeSubject();
        $r = new Request(['_username' => '', '_password' => '']);
        $this->expectException(CustomUserMessageAuthenticationException::class);
        $auth->getCredentials($r);
    }

    public function testOnAuthenticationSuccessRedirectsToStartWhenNoTarget(): void
    {
        $auth = $this->makeSubject();
        $request = new Request();
        // Provide a session to avoid null in TargetPathTrait
        $session = new \Symfony\Component\HttpFoundation\Session\Session(new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage());
        $request->setSession($session);
        $user = $this->getMockBuilder(\App\Entity\User::class)->disableOriginalConstructor()->getMock();
        $user->method('getUsername')->willReturn('dev');
        $token = new \Tests\Fixtures\TokenStub($user);
        $response = $auth->onAuthenticationSuccess($request, $token, 'main');
        $this->assertSame(302, $response->getStatusCode());
    }
}
