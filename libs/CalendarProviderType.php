<?php

declare(strict_types=1);

namespace IPSKalender;

final class CalendarProviderType
{
    public const APPLE = 0;
    public const CALDAV = 1;
    public const GOOGLE = 2;
    public const MICROSOFT = 3;
    public const ICS = 4;

    /** @var array<string, int> */
    private const TYPES_BY_KEY = [
        'apple'     => self::APPLE,
        'caldav'    => self::CALDAV,
        'google'    => self::GOOGLE,
        'microsoft' => self::MICROSOFT,
        'ics'       => self::ICS
    ];

    /**
     * Resolves the persisted provider type for a discovery provider key.
     *
     * @param string $provider Discovery provider key.
     * @return int|null Persisted provider type or null for an unsupported key.
     */
    public static function fromKey(string $provider): ?int
    {
        return self::TYPES_BY_KEY[$provider] ?? null;
    }

    /**
     * Checks whether a discovery provider key is supported.
     *
     * @param string $provider Discovery provider key.
     */
    public static function isSupportedKey(string $provider): bool
    {
        return array_key_exists($provider, self::TYPES_BY_KEY);
    }

    /**
     * Checks whether an integer represents a supported persisted provider type.
     *
     * @param int $provider Persisted provider type.
     */
    public static function isValid(int $provider): bool
    {
        return in_array($provider, self::TYPES_BY_KEY, true);
    }
}
