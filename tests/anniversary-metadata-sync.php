<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';

function anniversarySyncExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$calendar = new Calendar(9010);
$registerString = new ReflectionMethod(IPSModuleStrict::class, 'RegisterAttributeString');
$registerString->setAccessible(true);
$registerString->invoke($calendar, 'AnniversaryMetadata', '[]');
$registerString->invoke($calendar, 'BirthdayMetadata', '[]');
$registerInteger = new ReflectionMethod(IPSModuleStrict::class, 'RegisterAttributeInteger');
$registerInteger->setAccessible(true);
$registerInteger->invoke($calendar, 'LastSynchronization', 0);

$matchesEvents = new ReflectionMethod(Calendar::class, 'anniversaryMetadataMatchesEvents');
$matchesEvents->setAccessible(true);
$metadata = [
    'keys'    => ['id:series-1', 'uid:annual-1', 'resource:https://calendar.example/annual-1.ics'],
    'type'    => 'birthday',
    'date'    => '1980-02-29',
    'summary' => 'Leap birthday'
];
anniversarySyncExpect(
    $matchesEvents->invoke($calendar, $metadata, [['seriesId' => 'series-1']]),
    'Annual-event metadata must match a cached recurring series ID.'
);
anniversarySyncExpect(
    $matchesEvents->invoke(
        $calendar,
        $metadata,
        [['resourceUrl' => 'https://calendar.example/annual-1.ics']]
    ),
    'Annual-event metadata must match a cached CalDAV resource URL.'
);
anniversarySyncExpect(
    !$matchesEvents->invoke($calendar, $metadata, [['seriesId' => 'other-series']]),
    'Unrelated cached events must not match annual-event metadata.'
);

$candidateMethod = new ReflectionMethod(Calendar::class, 'anniversaryVerificationCandidates');
$candidateMethod->setAccessible(true);
$candidates = $candidateMethod->invoke($calendar, $metadata);
anniversarySyncExpect(
    $candidates === [[
        'seriesId'    => 'series-1',
        'resourceUrl' => 'https://calendar.example/annual-1.ics'
    ]],
    'Annual-event verification must preserve the recurring series and resource identity.'
);
anniversarySyncExpect(
    $candidateMethod->invoke($calendar, [
        'keys'    => ['uid:annual-without-series'],
        'type'    => 'birthday',
        'date'    => '1980-02-29',
        'summary' => 'No series ID'
    ]) === [],
    'Annual-event metadata without a provider series ID must not be deleted automatically.'
);

$dailyVerification = new ReflectionMethod(Calendar::class, 'shouldVerifyMissingAnniversaryMetadataToday');
$dailyVerification->setAccessible(true);
anniversarySyncExpect(
    $dailyVerification->invoke($calendar),
    'Missing annual-event metadata must be eligible for verification before the first synchronization.'
);

$writeInteger = new ReflectionMethod(IPSModuleStrict::class, 'WriteAttributeInteger');
$writeInteger->setAccessible(true);
$writeInteger->invoke($calendar, 'LastSynchronization', time());
anniversarySyncExpect(
    !$dailyVerification->invoke($calendar),
    'Repeated synchronizations on the same day must not repeatedly verify missing annual-event series.'
);
$writeInteger->invoke($calendar, 'LastSynchronization', (new DateTimeImmutable('yesterday'))->getTimestamp());
anniversarySyncExpect(
    $dailyVerification->invoke($calendar),
    'Missing annual-event metadata must become eligible for verification again on the next day.'
);

$writeString = new ReflectionMethod(IPSModuleStrict::class, 'WriteAttributeString');
$writeString->setAccessible(true);
$writeString->invoke($calendar, 'AnniversaryMetadata', json_encode([$metadata], JSON_THROW_ON_ERROR));
$reconcile = new ReflectionMethod(Calendar::class, 'reconcileAnniversaryMetadataAfterSynchronization');
$reconcile->setAccessible(true);
$writeInteger->invoke($calendar, 'LastSynchronization', time());
$reconcile->invoke($calendar, [], []);
anniversarySyncExpect(
    count(json_decode($calendar->GetAnniversaryList(), true, 512, JSON_THROW_ON_ERROR)) === 1,
    'A missing cached occurrence alone must never remove annual-event metadata.'
);
$writeInteger->invoke($calendar, 'LastSynchronization', (new DateTimeImmutable('yesterday'))->getTimestamp());
$reconcile->invoke($calendar, [], []);
anniversarySyncExpect(
    count(json_decode($calendar->GetAnniversaryList(), true, 512, JSON_THROW_ON_ERROR)) === 1,
    'A failed provider verification must preserve annual-event metadata.'
);

$gatewaySource = file_get_contents(__DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php');
anniversarySyncExpect(is_string($gatewaySource), 'The calendar account child gateway source could not be read.');
anniversarySyncExpect(
    str_contains($gatewaySource, '\'CheckRecurringSeries\'')
        && str_contains($gatewaySource, 'checkRecurringSeriesForChild')
        && str_contains($gatewaySource, 'property_exists($exception, \'httpStatus\')')
        && str_contains($gatewaySource, 'in_array($httpStatus, [404, 410], true)'),
    'The account gateway must distinguish confirmed provider deletions from temporary verification errors.'
);

echo 'Annual-event metadata synchronization tests passed.' . PHP_EOL;
