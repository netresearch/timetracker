<?php

declare(strict_types=1);

namespace Tests\Service\Ldap;

use App\Service\Ldap\LdapClientService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function is_scalar;

/**
 * @internal
 *
 * @coversNothing
 */
final class LdapClientServiceTest extends TestCase
{
    public function testSettersChainAndUsernameNormalization(): void
    {
        $ldapClientService = new LdapClientService();
        $ldapClientService->setHost('ldap.example')->setPort(636)->setReadUser('reader')->setReadPass('secret')
            ->setBaseDn('dc=example,dc=com')->setUseSSL(true)->setUserNameField('uid');

        $ldapClientService->setUserName(' Jürgen Müller');
        $ldapClientService->setUserPass('pass');

        // Indirectly verify normalization via reflection
        $reflectionClass = new ReflectionClass($ldapClientService);
        $reflectionProperty = $reflectionClass->getProperty('_userName');
        $userName = $reflectionProperty->getValue($ldapClientService);
        self::assertSame('juergen.mueller', trim(is_scalar($userName) ? (string) $userName : '', '.'));
    }

    public function testSetTeamsByLdapResponseHandlesMissingDn(): void
    {
        $ldapClientService = new LdapClientService();
        $reflectionClass = new ReflectionClass($ldapClientService);
        $reflectionMethod = $reflectionClass->getMethod('setTeamsByLdapResponse');
        $reflectionMethod->invoke($ldapClientService, [['cn' => ['No DN']]]);
        self::assertSame([], $ldapClientService->getTeams());
    }
}
