<?php

declare(strict_types=1);

namespace IPSKalender;

use InvalidArgumentException;

/** Builds storage bindings from authoritative records, never authenticates a request. */
final class AttachmentOwnerIdentity
{
    /**
     * Builds a versioned opaque key without titles, current dates or mutable ETags.
     * All arguments must come from verified server-side state, NOT browser claims.
     * Account ID must identify the provider account, not just its reusable instance.
     *
     * @param array<string, mixed> $source Instance ID, provider, account ID and calendar ID.
     * @param array<string, mixed> $event Authoritative normalized event/task record.
     * @return string SHA-256 storage binding, not a credential or ownership proof.
     */
    public static function key(array $source, array $event): string
    {
        if (!is_int($source['instanceId'] ?? null) || $source['instanceId'] <= 0) {
            throw new InvalidArgumentException('Invalid attachment calendar instance.');
        }
        $provider = self::required($source, 'provider');
        if (!in_array($provider, ['local', 'caldav', 'ical', 'microsoft', 'google'], true)) {
            throw new InvalidArgumentException('Unsupported attachment source.');
        }
        $scope = [$source['instanceId'], $provider, self::required($source, 'accountId'), self::required($source, 'calendarId')];
        if (($event['sourceType'] ?? '') === 'microsoft-todo' || ($event['taskProvider'] ?? '') === 'microsoft-todo') {
            if ($provider !== 'microsoft') {
                throw new InvalidArgumentException('Mismatched attachment task source.');
            }
            $identity = ['task', self::required($event, 'taskListId'), self::required($event, 'taskId')];
        } else {
            $type = self::required($event, 'recurrenceType');
            if (!in_array($type, ['single', 'master', 'occurrence', 'exception'], true)
                || ($type === 'single' && (!empty($event['recurring']) || !empty($event['recurrenceId'])))) {
                throw new InvalidArgumentException('Ambiguous attachment recurrence identity.');
            }
            $occurrence = in_array($type, ['occurrence', 'exception'], true);
            if (in_array($provider, ['microsoft', 'google'], true)) {
                $parent = self::required($event, $occurrence ? 'seriesId' : 'eventReference');
                $slot = $occurrence ? self::required($event, 'originalStart') : '';
            } else {
                $parent = self::required($event, 'uid');
                $slot = $occurrence ? self::required($event, 'recurrenceId') : '';
            }
            // A moved exception retains the original slot, not its new visible date.
            $identity = ['event', $occurrence ? 'occurrence' : $type, $parent, $slot];
        }
        return hash('sha256', json_encode(['version' => 1, 'source' => $scope, 'identity' => $identity], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $record */
    private static function required(array $record, string $field): string
    {
        $value = $record[$field] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > 8192
            || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Missing or invalid attachment identity field.');
        }
        return $value;
    }
}
