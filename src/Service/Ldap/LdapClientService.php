<?php

namespace App\Service\Ldap;

use App\Helper\LdapClient;
use Psr\Log\LoggerInterface;

class LdapClientService extends LdapClient
{
    public function __construct(?LoggerInterface $logger = null)
    {
        parent::__construct($logger);
    }
}
