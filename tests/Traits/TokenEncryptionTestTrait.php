<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Service\Security\TokenEncryptionService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Builds a real TokenEncryptionService with a fixed test key, so token
 * round-trips (encrypt/decrypt) behave as in production across the Jira tests.
 */
trait TokenEncryptionTestTrait
{
    private function createTokenEncryptionService(string $key = 'token-encryption-test-key'): TokenEncryptionService
    {
        $parameterBag = self::createStub(ParameterBagInterface::class);
        $parameterBag->method('get')->willReturn($key);

        return new TokenEncryptionService($parameterBag);
    }
}
