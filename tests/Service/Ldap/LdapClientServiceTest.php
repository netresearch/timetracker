<?php

declare(strict_types=1);

namespace Tests\Service\Ldap;

use App\Service\Ldap\LdapClientService;
use PHPUnit\Framework\TestCase;

class LdapClientServiceTest extends TestCase
{
    public function testSettersChainAndUsernameNormalization(): void
    {
        $ldapClientService = new LdapClientService();
        $ldapClientService->setHost('ldap.example')->setPort(636)->setReadUser('reader')->setReadPass('secret')
            ->setBaseDn('dc=example,dc=com')->setUseSSL(true)->setUserNameField('uid');

        $ldapClientService->setUserName(' Jürgen Müller');
        $ldapClientService->setUserPass('pass');

        // Indirectly verify normalization via reflection
        $reflectionClass = new \ReflectionClass($ldapClientService);
        $reflectionProperty = $reflectionClass->getProperty('_userName');
        $this->assertSame('juergen.mueller', trim((string) $reflectionProperty->getValue($ldapClientService), '.'));
    }

    public function testSetTeamsByLdapResponseHandlesMissingDn(): void
    {
        $ldapClientService = new LdapClientService();
        $reflectionClass = new \ReflectionClass($ldapClientService);
        $reflectionMethod = $reflectionClass->getMethod('setTeamsByLdapResponse');
        $reflectionMethod->invoke($ldapClientService, [['cn' => ['No DN']]]);
        $this->assertSame([], $ldapClientService->getTeams());
    }
}


