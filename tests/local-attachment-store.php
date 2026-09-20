<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/LocalAttachmentStore.php';

use IPSKalender\LocalAttachmentStore;

function attachmentAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function attachmentRejects(callable $operation): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | RuntimeException | JsonException $exception) {
        return;
    }
    throw new RuntimeException('Expected attachment operation to fail.');
}

$owner = hash('sha256', 'calendar42/event-A');
$other = hash('sha256', 'calendar43/event-A');
$request = hash('sha256', 'upload-1');
$store = new LocalAttachmentStore();
$bytes = "Private bytes\0\xff\n";
$meta = $store->add($owner, $request, 'Überweisung.pdf', base64_encode($bytes));
attachmentAssert(array_keys($meta) === ['id', 'name', 'revision', 'size'], 'Metadata must exclude content, owner and retry IDs.');
attachmentAssert($store->listForOwner($owner) === [$meta], 'Owner listing');
attachmentAssert($store->listForOwner($other) === [], 'Calendar isolation');
attachmentAssert($store->read($owner, $meta['id']) === $bytes, 'Exact binary roundtrip');
attachmentRejects(fn () => $store->read($other, $meta['id']));
attachmentRejects(fn () => $store->remove($other, $meta['id'], $meta['revision']));
attachmentRejects(fn () => $store->remove($owner, $meta['id'], 'stale'));
attachmentAssert($store->add($owner, $request, 'Überweisung.pdf', base64_encode($bytes)) === $meta, 'Retry must not duplicate originals.');
attachmentRejects(fn () => $store->add($owner, $request, 'changed.pdf', base64_encode($bytes)));
attachmentRejects(fn () => $store->add($other, $request, 'Überweisung.pdf', base64_encode($bytes)));
attachmentRejects(fn () => $store->add($owner, $request, 'Überweisung.pdf', base64_encode('changed')));

$snapshot = $store->exportSnapshot();
$restored = new LocalAttachmentStore($snapshot);
attachmentAssert($restored->read($owner, $meta['id']) === $bytes, 'Snapshot reload must preserve originals.');
attachmentAssert($restored->add($owner, $request, 'Überweisung.pdf', base64_encode($bytes)) === $meta, 'Retry after reload');
$restored->remove($owner, $meta['id'], $meta['revision']);
attachmentAssert($restored->listForOwner($owner) === [], 'Deliberate removal');
attachmentAssert($store->exportSnapshot() === $snapshot, 'Working copy must not mutate persisted snapshot.');

foreach (['../file', 'a/b', 'a\\b', "a\r\nX-Test: yes", "a\0b", 'a:b', ' file', '..', str_repeat('x', 181)] as $name) {
    attachmentRejects(fn () => $store->add($owner, hash('sha256', $name), $name, ''));
}
foreach (['%%%', 'YQ', "YQ==\n", str_repeat('A', 2_796_205)] as $invalid) {
    attachmentRejects(fn () => $store->add($owner, hash('sha256', $invalid), 'test', $invalid));
}
attachmentAssert($store->exportSnapshot() === $snapshot, 'Rejected writes must leave originals unchanged.');

$state = json_decode($snapshot, true, 8, JSON_THROW_ON_ERROR);
foreach (['size' => 999, 'revision' => str_repeat('0', 64), 'content' => 'bad', 'owner' => 'bad'] as $field => $bad) {
    $damaged = $state;
    $damaged['records'][0][$field] = $bad;
    attachmentRejects(fn () => new LocalAttachmentStore(json_encode($damaged, JSON_THROW_ON_ERROR)));
}
$state['records'][] = $state['records'][0];
attachmentRejects(fn () => new LocalAttachmentStore(json_encode($state, JSON_THROW_ON_ERROR)));
attachmentRejects(fn () => new LocalAttachmentStore('{'));
attachmentRejects(fn () => new LocalAttachmentStore('{"version":2,"records":[]}'));

$quota = new LocalAttachmentStore();
$large = base64_encode(str_repeat('x', LocalAttachmentStore::MAX_FILE_BYTES));
for ($i = 0; $i < 4; ++$i) {
    $quota->add($owner, hash('sha256', 'large' . $i), 'large.bin', $large);
}
attachmentRejects(fn () => $quota->add($owner, hash('sha256', 'overflow'), 'extra.bin', 'YQ=='));
attachmentRejects(fn () => $store->add($owner, hash('sha256', 'oversized'), 'large.bin', base64_encode(str_repeat('x', LocalAttachmentStore::MAX_FILE_BYTES + 1))));
attachmentAssert(count((new LocalAttachmentStore($quota->exportSnapshot()))->listForOwner($owner)) === 4, 'Full quota snapshot must restore.');
$count = new LocalAttachmentStore();
for ($i = 0; $i < 100; ++$i) {
    $count->add($owner, hash('sha256', 'empty' . $i), 'empty.txt', '');
}
attachmentRejects(fn () => $count->add($owner, hash('sha256', '101'), 'empty.txt', ''));
fwrite(STDOUT, "Local attachment working store: binary restore, owner isolation, retries, revisions, corruption and quotas passed.\n");
