<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/CalendarAttachmentPolicy.php';

use IPSKalender\CalendarAttachmentPolicy;

function checkAttachmentPolicy(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([-1, 0, 1, 2, 3] as $mode) {
    foreach ([false, true] as $local) {
        foreach ([false, true] as $provider) {
            $policy = new CalendarAttachmentPolicy($mode, $local, $provider);
            foreach (['list', 'download', 'upload', 'delete', 'unknown'] as $operation) {
                foreach (['local', 'provider', '', 'other'] as $destination) {
                    $expected = in_array($mode, [1, 2], true)
                        && in_array($operation, ['list', 'download', 'upload', 'delete'], true)
                        && ($mode === 2 || in_array($operation, ['list', 'download'], true))
                        && (($destination === 'local' && $local) || ($destination === 'provider' && $provider));
                    checkAttachmentPolicy($policy->allows($operation, $destination) === $expected, 'Policy matrix mismatch.');
                }
            }
        }
    }
}
foreach ([1, 2, -1] as $identityMode) {
    $policy = new CalendarAttachmentPolicy(2, true, true, $identityMode);
    checkAttachmentPolicy(!$policy->allows('download', 'local'), 'Unverified identity mode must not fall back to view access.');
}
checkAttachmentPolicy(!(new CalendarAttachmentPolicy())->allows('list', 'local'), 'Defaults must deny access.');
fwrite(STDOUT, "Attachment policy defaults, operation/destination matrix and identity fail-closed tests passed.\n");
