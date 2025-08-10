<?php

declare(strict_types=1);

namespace Tests\Service\Ldap;

use App\Service\Ldap\LdapClientService;
use PHPUnit\Framework\TestCase;

class LdapClientServiceTest extends TestCase
{
    public function testSettersChainAndUsernameNormalization(): void
    {
        $svc = new LdapClientService();
        $svc->setHost('ldap.example')->setPort(636)->setReadUser('reader')->setReadPass('secret')
            ->setBaseDn('dc=example,dc=com')->setUseSSL(true)->setUserNameField('uid');

        $svc->setUserName(' Jürgen Müller');
        $svc->setUserPass('pass');

        // Indirectly verify normalization via reflection
        $ref = new \ReflectionClass($svc);
        $prop = $ref->getProperty('_userName');
        $prop->setAccessible(true);
        $this->assertSame('juergen.mueller', trim($prop->getValue($svc), '.'));
    }

    public function testSetTeamsByLdapResponseHandlesMissingDn(): void
    {
        $svc = new LdapClientService();
        $ref = new \ReflectionClass($svc);
        $method = $ref->getMethod('setTeamsByLdapResponse');
        $method->setAccessible(true);
        $method->invoke($svc, [['cn' => ['No DN']]]);
        $this->assertSame([], $svc->getTeams());
    }
}


