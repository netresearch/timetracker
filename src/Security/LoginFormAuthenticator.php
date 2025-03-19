<?php

namespace App\Security;

use App\Entity\User;
use App\Helper\LdapClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = '_login';

    private $urlGenerator;
    private $entityManager;
    private $logger;

    public function __construct(
        UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->urlGenerator = $urlGenerator;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    public function authenticate(Request $request): Passport
    {
        $username = $request->request->get('username', '');
        $password = $request->request->get('password', '');
        $request->getSession()->set(Security::LAST_USERNAME, $username);

        return new Passport(
            new UserBadge($username),
            new CustomCredentials(
                function($credentials, User $user) {
                    try {
                        $client = new LdapClient($this->logger);
                        $client->setHost($_ENV['LDAP_HOST'])
                            ->setPort($_ENV['LDAP_PORT'])
                            ->setReadUser($_ENV['LDAP_READUSER'])
                            ->setReadPass($_ENV['LDAP_READPASS'])
                            ->setBaseDn($_ENV['LDAP_BASEDN'])
                            ->setUserName($user->getUsername())
                            ->setUserPass($credentials)
                            ->setUseSSL($_ENV['LDAP_USESSL'])
                            ->setUserNameField($_ENV['LDAP_USERNAMEFIELD'])
                            ->login();

                        return true;
                    } catch (\Exception $e) {
                        $this->logger->error('LDAP authentication failed', [
                            'exception' => $e,
                            'username' => $user->getUsername()
                        ]);
                        return false;
                    }
                },
                $password
            ),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('_start'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
