<?php

namespace App\Security;

use App\Helper\LdapClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authentication\SimpleFormAuthenticatorInterface;

class LdapAuthenticationHandler implements SimpleFormAuthenticatorInterface
{
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function supports(Request $request)
    {
        return $request->attributes->get('_route') === '_login'
            && $request->isMethod('POST');
    }

    public function supportsToken(TokenInterface $token, $providerKey)
    {
        return $token instanceof UsernamePasswordToken;
    }

    public function authenticateToken(TokenInterface $token, UserProviderInterface $userProvider, $providerKey)
    {
        try {
            $user = $userProvider->loadUserByUsername($token->getUsername());

            $client = new LdapClient($this->logger);
            $client->setHost($_ENV['LDAP_HOST'])
                ->setPort($_ENV['LDAP_PORT'])
                ->setReadUser($_ENV['LDAP_READUSER'])
                ->setReadPass($_ENV['LDAP_READPASS'])
                ->setBaseDn($_ENV['LDAP_BASEDN'])
                ->setUserName($user->getUsername())
                ->setUserPass($token->getCredentials())
                ->setUseSSL($_ENV['LDAP_USESSL'])
                ->setUserNameField($_ENV['LDAP_USERNAMEFIELD'])
                ->login();

            return new UsernamePasswordToken(
                $user,
                $token->getCredentials(),
                $providerKey,
                $user->getRoles()
            );
        } catch (\Exception $e) {
            $this->logger->error('LDAP authentication failed', [
                'exception' => $e,
                'username' => $token->getUsername()
            ]);
            throw new CustomUserMessageAuthenticationException('Invalid credentials.');
        }
    }

    public function createToken(Request $request, $username, $password, $providerKey)
    {
        return new UsernamePasswordToken($username, $password, $providerKey);
    }
}
