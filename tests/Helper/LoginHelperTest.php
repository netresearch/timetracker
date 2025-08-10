<?php

declare(strict_types=1);

namespace Tests\Helper;

use App\Helper\LoginHelper;
use PHPUnit\Framework\TestCase;

class LoginHelperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_COOKIE = [];
    }

    public function testGetCookieUserIdReturnsFalseWhenMissing(): void
    {
        $this->assertFalse(LoginHelper::getCookieUserId());
    }

    public function testCheckCookieUserNameReturnsFalseWhenMissing(): void
    {
        $this->assertFalse(LoginHelper::checkCookieUserName('user', 'secret'));
    }

    public function testGetCookieUserIdAndCheckUserName(): void
    {
        // fabricate a valid cookie manually using the same algorithm
        $userId = '42';
        $userName = 'jane';
        $secret = 's3cr3t';
        $token = bin2hex(random_bytes(8));

        $ref = new \ReflectionClass(LoginHelper::class);
        $hashMethod = $ref->getMethod('hash');
        $hashMethod->setAccessible(true);
        $hash = $hashMethod->invoke(null, $userName, $secret, $token);

        $_COOKIE[LoginHelper::COOKIE_NAME] = $userId . ':' . $hash . ':' . $token;

        $this->assertSame(42, LoginHelper::getCookieUserId());
        $this->assertTrue(LoginHelper::checkCookieUserName($userName, $secret));
        $this->assertFalse(LoginHelper::checkCookieUserName('john', $secret));
    }
}


