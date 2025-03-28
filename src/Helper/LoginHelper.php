<?php

namespace App\Helper;

/**
 * Helper for permanent timetracker login
 *
 * The "keep me logged in" cookie's design makes sure that
 *
 * 1. no attacker can generate such a cookie because the secret
 *    is known only to the server
 *
 * 2. the hash cannot be cracked (and the secrect obtained) by pre-computed
 *    hash tables because we add a long random token to the hashed data
 */
class LoginHelper
{
    const COOKIE_NAME   = 'nr_timetracker';


    public static function setCookie(string $userId, string $userName, string $secret): void
    {
        $token = bin2hex(random_bytes(32));
        setcookie(
            self::COOKIE_NAME,
            $userId
            . ':' . static::hash($userName, $secret, $token)
            . ':' . $token,
            ['expires' => time() + (14*24*60*60)]
        );
    }

    public static function deleteCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', ['expires' => time() - 7200]);
    }

    private static function getCookieData(): false|array
    {
        if (!is_array($_COOKIE)) {
            return false;
        }

        if (!array_key_exists(self::COOKIE_NAME, $_COOKIE)) {
            return false;
        }

        if (!preg_match('/^([0-9]+):([a-z0-9]+):([a-z0-9]+)$/i', (string) $_COOKIE[self::COOKIE_NAME], $matches)) {
            return false;
        }

        return [
            'userId' => (int)    $matches[1],
            'hash'   => $matches[2],
            'token'  => $matches[3],
        ];
    }

    public static function getCookieUserId()
    {
        $cookieData = self::getCookieData();
        if (!is_array($cookieData)) {
            return false;
        }

        return $cookieData['userId'];
    }

    public static function checkCookieUserName(string $expectedUserName, string $secret)
    {
        $cookieData = self::getCookieData();
        if (!is_array($cookieData)) {
            return false;
        }

        $expectedHash = static::hash($expectedUserName, $secret, $cookieData['token']);
        return $expectedHash == $cookieData['hash'];
    }

    private static function hash(string $userName, string $secret, string $token): string
    {
        return hash('sha256', $userName . $secret . $token);
    }
}
