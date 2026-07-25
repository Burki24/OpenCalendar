<?php

declare(strict_types=1);

namespace IPSKalender;

require_once __DIR__ . '/CalendarHttpOriginPolicyInterface.php';
require_once __DIR__ . '/CalDAVOriginPolicy.php';

/**
 * Restricts authenticated Google Calendar API requests and redirects to www.googleapis.com.
 */
final class GoogleCalendarOriginPolicy implements CalendarHttpOriginPolicyInterface
{
    private readonly CalDAVOriginPolicy $originPolicy;

    /**
     * Creates the fixed Google Calendar API origin policy.
     */
    public function __construct()
    {
        $this->originPolicy = new CalDAVOriginPolicy('https://www.googleapis.com');
    }

    /** @inheritDoc */
    public function isAllowedUrl(string $url): bool
    {
        return $this->originPolicy->isAllowedUrl($url);
    }

    /** @inheritDoc */
    public function resolveUrl(string $baseUrl, string $reference): string
    {
        return $this->originPolicy->resolveUrl($baseUrl, $reference);
    }

    /** @inheritDoc */
    public function requestBlockedMessage(): string
    {
        return 'The Google Calendar request URL belongs to an untrusted origin.';
    }

    /** @inheritDoc */
    public function redirectInvalidMessage(): string
    {
        return 'The Google Calendar redirect URL is invalid.';
    }

    /** @inheritDoc */
    public function redirectBlockedMessage(): string
    {
        return 'A Google Calendar redirect to an untrusted origin was blocked.';
    }
}
