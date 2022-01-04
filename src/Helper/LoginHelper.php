<?php

namespace App\Helper;

/**
 * Helper for permanent timetracker login
 */
class LoginHelper
{
    public const COOKIE_NAME   = 'nr_timetracker';


    public static function setCookie($userId, $userName)
    {
        setcookie(self::COOKIE_NAME,
            $userId . ':' . md5($userName),
            time() + (14*24*60*60));
    }

    public static function deleteCookie()
    {
        setcookie(self::COOKIE_NAME, '', time() - 7200);
    }

    private static function getCookieData()
    {
        if (!isset($_COOKIE) || !is_array($_COOKIE)) {
            return false;
        }

        if (!array_key_exists(self::COOKIE_NAME, $_COOKIE)) {
            return false;
        }

        if (!preg_match('/^([0-9]+):([a-z0-9]{32})$/i', $_COOKIE[self::COOKIE_NAME], $matches)) {
            return false;
        }

        return array(
            'userId'   => (int)     $matches[1],
            'userName' => (string)  $matches[2],
        );
    }

    public static function getCookieUserId()
    {
        $cookieData = self::getCookieData();
        if (!is_array($cookieData)) {
            return false;
        }

        return $cookieData['userId'];
    }

    public static function checkCookieUserName($userName)
    {
        $cookieData = self::getCookieData();
        if (!is_array($cookieData)) {
            return false;
        }

        return (bool) (md5($userName) === $cookieData['userName']);
    }
}
