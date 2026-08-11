<?php

declare(strict_types=1);

namespace IPSKalender;

final class ICalendarAuthentication
{
    public const AUTOMATIC = 0;
    public const URL_ACCESS_KEY = 1;
    public const USERNAME_PASSWORD = 2;

    /**
     * Resolves the credentials that may be sent with an iCalendar HTTP request.
     *
     * URL/access-key mode never emits HTTP credentials. Automatic mode keeps
     * backwards compatibility and only enables HTTP authentication when both
     * username and password are present.
     *
     * @return array{username: string, password: string}
     */
    public static function credentials(int $mode, string $username, string $password): array
    {
        $username = trim($username);

        if ($mode === self::URL_ACCESS_KEY) {
            return ['username' => '', 'password' => ''];
        }

        if ($mode === self::USERNAME_PASSWORD) {
            return ['username' => $username, 'password' => $password];
        }

        if ($username === '' || $password === '') {
            return ['username' => '', 'password' => ''];
        }

        return ['username' => $username, 'password' => $password];
    }

    /**
     * Returns whether the supplied iCalendar authentication mode is supported.
     */
    public static function isValidMode(int $mode): bool
    {
        return in_array($mode, [
            self::AUTOMATIC,
            self::URL_ACCESS_KEY,
            self::USERNAME_PASSWORD
        ], true);
    }
}
