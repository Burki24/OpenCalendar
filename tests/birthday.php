<?php

declare(strict_types=1);

require_once __DIR__ . '/stubs/autoload.php';
require_once __DIR__ . '/../Kalender/module.php';

function assertBirthday(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$previousTimezone = date_default_timezone_get();
date_default_timezone_set('Europe/Berlin');
try {
    $calendar = new Kalender(9001);
    $registerBirthdayAttribute = new ReflectionMethod(IPSModuleStrict::class, 'RegisterAttributeString');
    $registerBirthdayAttribute->setAccessible(true);
    $registerBirthdayAttribute->invoke($calendar, 'BirthdayMetadata', '[]');

    $applyDefaults = new ReflectionMethod(Kalender::class, 'applyBirthdayEventDefaults');
    $applyDefaults->setAccessible(true);
    $event = ['summary' => 'Max Mustermann'];
    $args = [&$event, '1993-07-20'];
    $applyDefaults->invokeArgs($calendar, $args);
    assertBirthday($event['allDay'] === true, 'Birthday defaults must create an all-day event.');
    assertBirthday($event['start'] === '1993-07-20' && $event['end'] === '1993-07-21', 'Birthday defaults must use the birth date as initial event date.');
    assertBirthday(($event['recurrence']['frequency'] ?? '') === 'YEARLY', 'Birthday defaults must create yearly recurrence.');

    $upsert = new ReflectionMethod(Kalender::class, 'upsertBirthdayMetadata');
    $upsert->setAccessible(true);
    $upsert->invoke($calendar, ['uid' => 'birthday-1', 'summary' => 'Max Mustermann'], '1993-07-20', 'Max Mustermann');

    $list = json_decode($calendar->GetBirthdayList(0), true, 512, JSON_THROW_ON_ERROR);
    assertBirthday(count($list) === 1, 'Stored birthday metadata must be returned.');
    assertBirthday(($list[0]['birthDate'] ?? '') === '1993-07-20', 'Birthday list must expose the birth date.');
    assertBirthday(isset($list[0]['nextBirthday'], $list[0]['age'], $list[0]['daysUntil']), 'Birthday list must calculate next date, age, and days until.');

    $todayBirthDate = '2000-' . date('m-d');
    $upsert->invoke($calendar, ['uid' => 'birthday-today', 'summary' => 'Birthday Today'], $todayBirthDate, 'Birthday Today');
    $upcoming = json_decode($calendar->GetBirthdayList(1), true, 512, JSON_THROW_ON_ERROR);
    assertBirthday(
        count(array_filter($upcoming, static fn (array $birthday): bool => ($birthday['name'] ?? '') === 'Birthday Today')) === 1,
        'A freely chosen positive day window must include a birthday occurring today.'
    );
    try {
        $calendar->GetBirthdayList(-1);
        throw new RuntimeException('Negative birthday look-ahead days must be rejected.');
    } catch (InvalidArgumentException) {
    }

    $presentation = new ReflectionMethod(Kalender::class, 'applyBirthdayPresentation');
    $presentation->setAccessible(true);
    $presented = $presentation->invoke($calendar, [
        'uid' => 'birthday-1',
        'summary' => 'Max Mustermann',
        'start' => '2026-07-20',
        'originalStart' => '2026-07-20'
    ], ['keys' => ['uid:birthday-1'], 'birthDate' => '1993-07-20', 'summary' => 'Max Mustermann']);
    assertBirthday(($presented['age'] ?? null) === 33, 'Birthday occurrence age must use the occurrence year.');
    assertBirthday(($presented['displaySummary'] ?? '') === 'Max Mustermann (33J)', 'Birthday display summary must append the calculated age.');

    $leap = new ReflectionMethod(Kalender::class, 'nextBirthdayDate');
    $leap->setAccessible(true);
    $nextLeap = $leap->invoke($calendar, '2000-02-29', new DateTimeImmutable('2026-08-17'));
    assertBirthday($nextLeap->format('Y-m-d') === '2028-02-29', 'Leap-day birthdays must advance to the next valid February 29.');
} finally {
    date_default_timezone_set($previousTimezone);
}

echo 'Birthday tests passed.' . PHP_EOL;
