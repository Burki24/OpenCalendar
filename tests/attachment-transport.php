<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/CalendarAttachmentTransport.php';

use IPSKalender\CalendarAttachmentTransport;

$cases = [
    [[], false, false],
    [['HTTPS' => 'on'], false, true],
    [['HTTPS' => '1'], false, true],
    [['HTTPS' => 'off', 'REMOTE_ADDR' => '192.168.178.20'], false, false],
    [['REMOTE_ADDR' => '192.168.178.20'], true, true],
    [['REMOTE_ADDR' => '10.0.0.3'], true, true],
    [['REMOTE_ADDR' => '172.31.0.3'], true, true],
    [['REMOTE_ADDR' => '172.32.0.3'], true, false],
    [['REMOTE_ADDR' => '127.0.0.1'], true, true],
    [['REMOTE_ADDR' => '::1'], true, true],
    [['REMOTE_ADDR' => 'fd00::123'], true, true],
    [['REMOTE_ADDR' => '::ffff:192.168.1.2'], true, true],
    [['REMOTE_ADDR' => '8.8.8.8'], true, false],
    [['REMOTE_ADDR' => '2001:4860::1'], true, false],
    [['REMOTE_ADDR' => 'localhost'], true, false],
    [['REMOTE_ADDR' => ['127.0.0.1']], true, false],
    [['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '127.0.0.1'], true, false],
    [['HTTP_FORWARDED' => 'proto=https', 'REMOTE_ADDR' => '127.0.0.1'], true, false],
    [['HTTP_X_FORWARDED_FOR' => '192.168.1.1', 'REMOTE_ADDR' => '127.0.0.1'], true, false],
    [['HTTP_HOST' => 'test.ipmagic.de', 'SERVER_PORT' => 443], false, false],
];
foreach ($cases as $index => [$server, $allowHttp, $expected]) {
    if (CalendarAttachmentTransport::allows($server, $allowHttp) !== $expected) {
        throw new RuntimeException('Attachment transport case ' . $index);
    }
}
fwrite(STDOUT, "Attachment transport: TLS, explicit local HTTP and untrusted proxy rejection passed.\n");
