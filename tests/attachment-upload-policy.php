<?php

declare(strict_types=1);

require_once __DIR__ . '/local-calendar-module.php';

$calendar = localModuleNew(7042);
$calendar->properties['AttachmentMode'] = 2;
$calendar->properties['AttachmentAllowLocal'] = true;
$gate = new ReflectionMethod(Calendar::class, 'localAttachmentOperation');
$upload = static fn (string $name, string $content): mixed => $gate->invoke($calendar, 'upload', hash('sha256', 'owner'), [
    'requestId' => hash('sha256', $name . $content), 'name' => $name, 'content' => $content
]);
foreach ([
    ['page.html', '<script>alert(1)</script>'],
    ['image.svg', '<svg/>'], ['run.exe', 'MZ'], ['archive.zip', 'PK'],
    ['fake.pdf', 'not a PDF'], ['fake.png', 'not an image'],
    ['fake.jpg', 'not an image'], ['text.txt', "binary\0text"],
    ['../text.txt', 'text'], ["report\u{202E}fdp.txt", 'text'],
    ['CON.txt', 'text'], ['file.txt.', 'text'], ['macro.docm', 'PK']
] as [$name, $bytes]) {
    $before = $calendar->attributes['LocalAttachmentOriginals'];
    $rejected = false;
    try {
        $upload($name, base64_encode($bytes));
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    localModuleCheck($rejected, 'Unsafe upload accepted: ' . $name);
    localModuleCheck($before === $calendar->attributes['LocalAttachmentOriginals'] && $GLOBALS['localLocks'] === [], 'Rejection must preserve originals and release locks.');
}
$meta = $upload('Notiz.TXT', base64_encode("Grüße\nTermin\t10 Uhr"));
localModuleCheck($meta['name'] === 'Notiz.TXT', 'UTF-8 text upload failed.');
$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
localModuleCheck(IPSKalender\AttachmentUploadPolicy::validate('pixel.png', $png) === 'image/png', 'PNG header detection');
// Structural PDF admission is deliberately not a full PDF parser or sanitizer.
localModuleCheck(IPSKalender\AttachmentUploadPolicy::validate('document.pdf', base64_encode("%PDF-1.7\n%%EOF\n")) === 'application/pdf', 'PDF envelope detection');
foreach (['!!!!', 'YQ', "YQ==\n", base64_encode(str_repeat('a', IPSKalender\LocalAttachmentStore::MAX_FILE_BYTES + 1))] as $encoded) {
    $before = $calendar->attributes['LocalAttachmentOriginals'];
    $rejected = false;
    try {
        $upload('invalid.txt', $encoded);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    localModuleCheck($rejected && $before === $calendar->attributes['LocalAttachmentOriginals'] && $GLOBALS['localLocks'] === [], 'Malformed/oversize content must not persist or retain locks.');
}
localModuleCheck(IPSKalender\AttachmentUploadPolicy::validate('limit.txt', base64_encode(str_repeat('a', IPSKalender\LocalAttachmentStore::MAX_FILE_BYTES))) === 'text/plain', 'Exact size limit should pass.');
fwrite(STDOUT, "Attachment upload policy rejects unsupported types, mismatches and unsafe names before persistence.\n");
