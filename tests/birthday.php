<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';

function assertAnniversary(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');
try {
    $calendar = new Kalender(9001);
    $registerAttribute = new ReflectionMethod(IPSModuleStrict::class, 'RegisterAttributeString');
    $registerAttribute->setAccessible(true);
    $registerAttribute->invoke($calendar, 'AnniversaryMetadata', '[]');
    $registerAttribute->invoke($calendar, 'BirthdayMetadata', '[]');

    $applyDefaults = new ReflectionMethod(Kalender::class, 'applyAnniversaryEventDefaults');
    $applyDefaults->setAccessible(true);
    $event = ['summary' => 'Max Mustermann'];
    $args = [&$event, '1993-07-20'];
    $applyDefaults->invokeArgs($calendar, $args);
    assertAnniversary($event['allDay'] === true, 'Annual-event defaults must create an all-day event.');
    assertAnniversary(
        $event['start'] === '1993-07-20' && $event['end'] === '1993-07-21',
        'Annual-event defaults must use the stored date as initial event date.'
    );
    assertAnniversary(
        ($event['recurrence']['frequency'] ?? '') === 'YEARLY',
        'Annual-event defaults must create yearly recurrence.'
    );

    $upsert = new ReflectionMethod(Kalender::class, 'upsertAnniversaryMetadata');
    $upsert->setAccessible(true);
    $upsert->invoke(
        $calendar,
        ['uid' => 'birthday-1', 'summary' => 'Max Mustermann'],
        'birthday',
        '1993-07-20',
        'Max Mustermann'
    );
    $upsert->invoke(
        $calendar,
        ['uid' => 'anniversary-1', 'summary' => 'Firma gegründet'],
        'anniversary',
        '2012-05-12',
        'Firma gegründet'
    );
    $upsert->invoke(
        $calendar,
        ['uid' => 'wedding-1', 'summary' => 'Hochzeit'],
        'wedding',
        '2016-08-10',
        'Hochzeit'
    );
    $upsert->invoke(
        $calendar,
        ['uid' => 'death-1', 'summary' => 'Max Mustermann'],
        'death',
        '2020-11-03',
        'Max Mustermann'
    );

    $list = json_decode($calendar->GetAnniversaryList(), true, 512, JSON_THROW_ON_ERROR);
    assertAnniversary(count($list) === 4, 'All stored annual-event types must be returned without a type filter.');
    assertAnniversary(
        count(array_unique(array_column($list, 'anniversaryType'))) === 4,
        'Annual-event list must expose every supported type.'
    );

    $weddings = json_decode($calendar->GetAnniversaryList(0, 'wedding'), true, 512, JSON_THROW_ON_ERROR);
    assertAnniversary(count($weddings) === 1, 'Annual-event type filter must return only the requested type.');
    assertAnniversary(
        ($weddings[0]['anniversaryDate'] ?? '') === '2016-08-10'
            && isset($weddings[0]['nextDate'], $weddings[0]['years'], $weddings[0]['daysUntil']),
        'Annual-event list must expose the stored date, next date, year count, and days until.'
    );

    $setCalendar = new Kalender(9003);
    $registerAttribute->invoke($setCalendar, 'AnniversaryMetadata', '[]');
    $registerAttribute->invoke($setCalendar, 'BirthdayMetadata', '[]');
    $registerAttribute->invoke($setCalendar, 'CachedEvents', '[]');
    assertAnniversary(
        $setCalendar->SetAnniversary(
            json_encode([
                'seriesId'       => 'set-series-1',
                'recurrenceType' => 'master',
                'recurring'      => true,
                'summary'        => 'Hochzeitstag'
            ], JSON_THROW_ON_ERROR),
            'wedding',
            '2010-06-19'
        ),
        'SetAnniversary must accept every supported annual-event type for an existing series.'
    );
    $setList = json_decode($setCalendar->GetAnniversaryList(0, 'wedding'), true, 512, JSON_THROW_ON_ERROR);
    assertAnniversary(
        count($setList) === 1
            && ($setList[0]['anniversaryDate'] ?? '') === '2010-06-19'
            && ($setList[0]['name'] ?? '') === 'Hochzeitstag',
        'SetAnniversary must persist type, original date, and summary.'
    );
    $setCalendar->SetAnniversary(
        json_encode([
            'seriesId'       => 'set-series-1',
            'recurrenceType' => 'master',
            'recurring'      => true,
            'summary'        => 'Geburtstag'
        ], JSON_THROW_ON_ERROR),
        'birthday',
        '1988-06-19'
    );
    $setBirthdays = json_decode($setCalendar->GetBirthdayList(), true, 512, JSON_THROW_ON_ERROR);
    assertAnniversary(
        count($setBirthdays) === 1 && ($setBirthdays[0]['birthDate'] ?? '') === '1988-06-19',
        'SetAnniversary must allow changing the annual-event type while keeping birthday compatibility output.'
    );
    try {
        $setCalendar->SetAnniversary(
            json_encode(['uid' => 'single-event'], JSON_THROW_ON_ERROR),
            'birthday',
            '1988-06-19'
        );
        throw new RuntimeException('SetAnniversary must reject non-recurring events.');
    } catch (InvalidArgumentException) {
    }

    $birthdays = json_decode($calendar->GetBirthdayList(), true, 512, JSON_THROW_ON_ERROR);
    assertAnniversary(count($birthdays) === 1, 'Birthday compatibility API must keep filtering to birthdays.');
    assertAnniversary(
        ($birthdays[0]['birthDate'] ?? '') === '1993-07-20'
            && isset($birthdays[0]['nextBirthday'], $birthdays[0]['age']),
        'Birthday compatibility API must keep the legacy birthday fields.'
    );

    $legacyCalendar = new Kalender(9002);
    $registerAttribute->invoke($legacyCalendar, 'AnniversaryMetadata', '[]');
    $registerAttribute->invoke(
        $legacyCalendar,
        'BirthdayMetadata',
        json_encode([[
            'keys'      => ['uid:legacy-birthday'],
            'birthDate' => '1980-04-03',
            'summary'   => 'Legacy Birthday'
        ]], JSON_THROW_ON_ERROR)
    );
    $legacyList = json_decode($legacyCalendar->GetAnniversaryList(), true, 512, JSON_THROW_ON_ERROR);
    assertAnniversary(
        count($legacyList) === 1 && ($legacyList[0]['anniversaryType'] ?? '') === 'birthday',
        'Legacy BirthdayMetadata must remain readable as birthday annual-event metadata.'
    );

    $todayDate = '2000-' . date('m-d');
    $upsert->invoke(
        $calendar,
        ['uid' => 'anniversary-today', 'summary' => 'Heute'],
        'anniversary',
        $todayDate,
        'Heute'
    );
    $upcoming = json_decode($calendar->GetAnniversaryList(1, 'anniversary'), true, 512, JSON_THROW_ON_ERROR);
    assertAnniversary(
        count(array_filter($upcoming, static fn (array $entry): bool => ($entry['name'] ?? '') === 'Heute')) === 1,
        'A freely chosen positive day window must include an annual event occurring today.'
    );

    try {
        $calendar->GetAnniversaryList(-1);
        throw new RuntimeException('Negative annual-event look-ahead days must be rejected.');
    } catch (InvalidArgumentException) {
    }
    try {
        $calendar->GetAnniversaryList(0, 'invalid');
        throw new RuntimeException('Unsupported annual-event types must be rejected.');
    } catch (InvalidArgumentException) {
    }

    $presentation = new ReflectionMethod(Kalender::class, 'applyAnniversaryPresentation');
    $presentation->setAccessible(true);
    $presented = $presentation->invoke($calendar, [
        'uid'           => 'wedding-1',
        'summary'       => 'Hochzeit',
        'start'         => '2026-08-10',
        'originalStart' => '2026-08-10'
    ], [
        'keys'    => ['uid:wedding-1'],
        'type'    => 'wedding',
        'date'    => '2016-08-10',
        'summary' => 'Hochzeit'
    ]);
    assertAnniversary(($presented['years'] ?? null) === 10, 'Annual-event year count must use the occurrence year.');
    assertAnniversary(
        ($presented['displaySummary'] ?? '') === 'Hochzeit (10J)',
        'Annual-event display summary must append the calculated year count.'
    );

    $presentedBirthday = $presentation->invoke($calendar, [
        'uid'           => 'birthday-1',
        'summary'       => 'Max Mustermann',
        'start'         => '2026-07-20',
        'originalStart' => '2026-07-20'
    ], [
        'keys'    => ['uid:birthday-1'],
        'type'    => 'birthday',
        'date'    => '1993-07-20',
        'summary' => 'Max Mustermann'
    ]);
    assertAnniversary(
        ($presentedBirthday['birthday'] ?? false) === true
            && ($presentedBirthday['birthDate'] ?? '') === '1993-07-20'
            && ($presentedBirthday['age'] ?? null) === 33,
        'Birthday events must retain the compatibility birthday fields.'
    );

    $leap = new ReflectionMethod(Kalender::class, 'nextAnniversaryDate');
    $leap->setAccessible(true);
    $nextLeap = $leap->invoke($calendar, '2000-02-29', new DateTimeImmutable('2026-08-17'));
    assertAnniversary(
        $nextLeap->format('Y-m-d') === '2028-02-29',
        'Leap-day annual events must advance to the next valid February 29.'
    );
} finally {
    date_default_timezone_set($previousTimezone);
}

echo 'Annual-event tests passed.' . PHP_EOL;
