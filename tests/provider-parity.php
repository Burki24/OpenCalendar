<?php

declare(strict_types=1);

use IPSKalender\CalDAVProvider;
use IPSKalender\CalendarEventLookupProviderInterface;
use IPSKalender\CalendarProviderInterface;
use IPSKalender\GoogleCalendarProvider;
use IPSKalender\ICalendarFeedProvider;
use IPSKalender\ICalendarFileProvider;
use IPSKalender\ICalendarSubscriptionProvider;
use IPSKalender\MicrosoftCalendarProvider;
use IPSKalender\RecurringCalendarProviderInterface;

require_once __DIR__ . '/../libs/CalendarProviderInterface.php';
require_once __DIR__ . '/../libs/CalendarEventLookupProviderInterface.php';
require_once __DIR__ . '/../libs/RecurringCalendarProviderInterface.php';
require_once __DIR__ . '/../libs/GoogleCalendarProvider.php';
require_once __DIR__ . '/../libs/MicrosoftCalendarProvider.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';
require_once __DIR__ . '/../libs/ICalendarFeedProvider.php';
require_once __DIR__ . '/../libs/ICalendarFileProvider.php';
require_once __DIR__ . '/../libs/ICalendarSubscriptionProvider.php';

function assertProviderParity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @return string
 */
function providerSource(string $file): string
{
    $source = file_get_contents(__DIR__ . '/../libs/' . $file);
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Provider source could not be read: ' . $file);
    }

    return $source;
}

/**
 * @param list<string> $markers
 */
function assertProviderMarkers(string $provider, string $source, array $markers): void
{
    foreach ($markers as $marker) {
        assertProviderParity(
            str_contains($source, $marker),
            sprintf('%s provider parity marker is missing: %s', $provider, $marker)
        );
    }
}

/**
 * @param list<string> $patterns
 */
function assertProviderPatterns(string $provider, string $source, array $patterns): void
{
    foreach ($patterns as $pattern) {
        assertProviderParity(
            preg_match($pattern, $source) === 1,
            sprintf('%s provider parity pattern is missing: %s', $provider, $pattern)
        );
    }
}

$providers = [
    'Google'           => [
        'class'                 => GoogleCalendarProvider::class,
        'source'                => 'GoogleCalendarProvider.php',
        'lookup'                => true,
        'recurring'             => true,
        'writeStatus'           => 'writable',
        'writeTransparency'     => 'writable',
        'allDayTransparency'    => 'OPAQUE',
        'requiredMarkers'       => [
            'CalendarEventState::normalizeStatus',
            'CalendarEventState::normalizeTransparency',
            '($item[\'status\'] ?? \'\') === \'cancelled\''
        ]
    ],
    'Microsoft'        => [
        'class'                 => MicrosoftCalendarProvider::class,
        'source'                => 'MicrosoftCalendarProvider.php',
        'lookup'                => true,
        'recurring'             => true,
        'writeStatus'           => 'readonly',
        'writeTransparency'     => 'writable',
        'allDayTransparency'    => 'TRANSPARENT',
        'requiredMarkers'       => [
            'CalendarEventState::STATUS_CANCELLED',
            'CalendarEventState::STATUS_CONFIRMED',
            'CalendarEventState::TRANSP_TRANSPARENT',
            'CalendarEventState::TRANSP_OPAQUE',
            '(bool) ($item[\'isCancelled\'] ?? false)'
        ]
    ],
    'CalDAV'           => [
        'class'                 => CalDAVProvider::class,
        'source'                => 'CalDAVProvider.php',
        'lookup'                => true,
        'recurring'             => true,
        'writeStatus'           => 'writable',
        'writeTransparency'     => 'writable',
        'allDayTransparency'    => 'OPAQUE',
        'requiredMarkers'       => [
            'ICalendarCodec::parseEvents',
            'CalendarEventState::STATUS_CONFIRMED',
            'CalendarEventState::TRANSP_OPAQUE'
        ]
    ],
    'ICS feed'         => [
        'class'                 => ICalendarFeedProvider::class,
        'source'                => 'ICalendarFeedProvider.php',
        'lookup'                => false,
        'recurring'             => false,
        'writeStatus'           => 'unsupported',
        'writeTransparency'     => 'unsupported',
        'allDayTransparency'    => '',
        'requiredMarkers'       => [
            'ICalendarCodec::parseEventsInRange',
            'iCalendar subscriptions are read-only.'
        ]
    ],
    'ICS file'         => [
        'class'                 => ICalendarFileProvider::class,
        'source'                => 'ICalendarFileProvider.php',
        'lookup'                => false,
        'recurring'             => false,
        'writeStatus'           => 'unsupported',
        'writeTransparency'     => 'unsupported',
        'allDayTransparency'    => '',
        'requiredMarkers'       => [
            'ICalendarCodec::parseEventsInRange',
            'Local iCalendar files are read-only.'
        ]
    ],
    'ICS subscription' => [
        'class'                 => ICalendarSubscriptionProvider::class,
        'source'                => 'ICalendarSubscriptionProvider.php',
        'lookup'                => false,
        'recurring'             => false,
        'writeStatus'           => 'delegated',
        'writeTransparency'     => 'delegated',
        'allDayTransparency'    => '',
        'requiredMarkers'       => [
            'CalendarEventTranslation::translateEvents',
            '->getEvents($subscription[\'providerReference\'], $start, $end)',
            'iCalendar subscriptions are read-only.'
        ]
    ]
];

$baseMethods = [
    'testConnection',
    'getCalendars',
    'getEvents',
    'createEvent',
    'updateEvent',
    'deleteEvent'
];

foreach ($providers as $providerName => $contract) {
    $class = (string) $contract['class'];
    $reflection = new ReflectionClass($class);
    assertProviderParity(
        $reflection->implementsInterface(CalendarProviderInterface::class),
        $providerName . ' must implement CalendarProviderInterface.'
    );

    foreach ($baseMethods as $methodName) {
        assertProviderParity(
            $reflection->hasMethod($methodName) && $reflection->getMethod($methodName)->isPublic(),
            sprintf('%s must expose public %s().', $providerName, $methodName)
        );
    }

    $supportsLookup = $reflection->implementsInterface(CalendarEventLookupProviderInterface::class);
    assertProviderParity(
        $supportsLookup === (bool) $contract['lookup'],
        $providerName . ' direct event lookup capability differs from the parity matrix.'
    );
    if ($supportsLookup) {
        assertProviderParity(
            $reflection->hasMethod('getEventForEdit') && $reflection->getMethod('getEventForEdit')->isPublic(),
            $providerName . ' must expose public getEventForEdit().'
        );
    }

    $supportsRecurrence = $reflection->implementsInterface(RecurringCalendarProviderInterface::class);
    assertProviderParity(
        $supportsRecurrence === (bool) $contract['recurring'],
        $providerName . ' recurrence capability differs from the parity matrix.'
    );
    if ($supportsRecurrence) {
        foreach (['getRecurringSeries', 'getRecurringFollowing'] as $methodName) {
            assertProviderParity(
                $reflection->hasMethod($methodName) && $reflection->getMethod($methodName)->isPublic(),
                sprintf('%s must expose public %s().', $providerName, $methodName)
            );
        }
    }

    $source = providerSource((string) $contract['source']);
    assertProviderMarkers($providerName, $source, $contract['requiredMarkers']);

    if ($contract['writeStatus'] === 'writable') {
        assertProviderPatterns($providerName, $source, ['/\'writeStatus\'\s*=>\s*\$canWrite/']);
    } elseif ($contract['writeStatus'] === 'readonly') {
        assertProviderPatterns($providerName, $source, ['/\'writeStatus\'\s*=>\s*false/']);
    } elseif ($contract['writeStatus'] === 'unsupported') {
        assertProviderPatterns($providerName, $source, [
            '/\'create\'\s*=>\s*false/',
            '/\'update\'\s*=>\s*false/',
            '/\'delete\'\s*=>\s*false/'
        ]);
    }

    if ($contract['writeTransparency'] === 'writable') {
        assertProviderPatterns($providerName, $source, ['/\'writeTransparency\'\s*=>\s*\$canWrite/']);
    } elseif ($contract['writeTransparency'] === 'readonly') {
        assertProviderPatterns($providerName, $source, ['/\'writeTransparency\'\s*=>\s*false/']);
    }

    if ($contract['allDayTransparency'] !== '') {
        assertProviderPatterns($providerName, $source, [
            '/\'defaultStatus\'\s*=>\s*CalendarEventState::STATUS_CONFIRMED/',
            '/\'defaultTransparency\'\s*=>\s*CalendarEventState::TRANSP_OPAQUE/',
            '/\'defaultAllDayTransparency\'\s*=>\s*CalendarEventState::TRANSP_' .
                preg_quote((string) $contract['allDayTransparency'], '/') . '/'
        ]);
    }
}

$codecSource = providerSource('ICalendarCodec.php');
assertProviderMarkers('iCalendar codec', $codecSource, [
    'CalendarEventState::normalizeStatus',
    'CalendarEventState::normalizeTransparency'
]);

$calendarModuleSource = file_get_contents(__DIR__ . '/../Kalender/module.php');
assertProviderParity(
    is_string($calendarModuleSource)
        && str_contains($calendarModuleSource, 'CalendarEventState::filterVisibleEvents($events)')
        && str_contains($calendarModuleSource, '$this->assertEventAvailable($currentEvent);')
        && str_contains($calendarModuleSource, '$this->assertEventAvailable($series);')
        && str_contains($calendarModuleSource, '$this->assertEventAvailable($following);'),
    'Calendar must enforce one provider-neutral cancelled-event visibility policy for lists and direct lookups.'
);

fwrite(STDOUT, "Provider parity matrix tests passed.\n");
