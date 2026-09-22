<?php

declare(strict_types=1);

require_once __DIR__ . '/local-calendar-module.php';
require_once __DIR__ . '/microsoft-attachment-metadata.php';
require_once __DIR__ . '/../libs/ICalendarSubscriptionProvider.php';
require_once __DIR__ . '/../libs/ICalendarFileProvider.php';
require_once __DIR__ . '/../libs/CalDAVProvider.php';
require_once __DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php';

final class AttachmentMetadataGateway
{
    use KalenderKontoChildGatewayTrait;
    use Burki24\SymconModuleHelper\DataFlowHelper;

    private const PROVIDER_MICROSOFT = 3;
    private const PROVIDER_APPLE = 0;
    private const PROVIDER_CALDAV = 1;
    private const PROVIDER_GOOGLE = 2;
    private const PROVIDER_ICS = 4;
    private const DATA_ID_FROM_CHILD = '{4E535B1D-69C7-AC77-1372-0282B21BAEC9}';
    public int $provider = 3;
    public string $cachedCalendars = '[{"id":"calendar42","providerId":"cal","url":"https://graph.microsoft.com/v1.0/me/calendars/cal"}]';
    public ?IPSKalender\CalendarProviderInterface $providerObject = null;

    public function __construct(public AttachmentMetadataHttp $http)
    {
    }

    private function ReadPropertyInteger(string $name): int
    {
        return $this->provider;
    }

    private function ReadAttributeString(string $name): string
    {
        return $this->cachedCalendars;
    }

    private function createTrustedCloudHttpClient(IPSKalender\CalendarHttpOriginPolicyInterface $policy): IPSKalender\CalendarHttpClientInterface
    {
        attachmentMetadataCheck(!$policy->isAllowedUrl('https://outside.invalid'), 'Graph client must restrict redirects.');
        return $this->http;
    }

    private function getMicrosoftAccessToken(): string
    {
        return 'token';
    }

    private function createProvider(): IPSKalender\CalendarProviderInterface
    {
        return $this->providerObject ?? throw new LogicException('Unexpected provider creation.');
    }

    private function SendSafeDebug(string $name, mixed $data): void
    {
        throw new LogicException('Attachment gateway must not log private requests or failures.');
    }
}

final class ProviderAttachmentCalendar extends Calendar
{
    public ?Closure $afterReply = null;

    public function __construct(int $id, public AttachmentMetadataGateway $gateway)
    {
        parent::__construct($id);
    }

    public function HasActiveParent(): bool
    {
        return true;
    }

    public function SendDataToParent(string $json): string
    {
        $reply = $this->gateway->ForwardData($json);
        if ($this->afterReply !== null) {
            ($this->afterReply)();
        }
        return $reply;
    }
}

$http = new AttachmentMetadataHttp([[200, ['id' => 'evt']], [200, ['value' => [$file]]]]);
$gateway = new AttachmentMetadataGateway($http);
$calendar = new ProviderAttachmentCalendar(8042, $gateway);
$calendar->Create();
$calendar->attributes['RuntimeReady'] = true;
$calendar->properties['CalendarID'] = 'calendar42';
$selector = '{"eventReference":"evt"}';
attachmentMetadataReject(fn () => $calendar->ListProviderAttachments($selector));
attachmentMetadataCheck($http->requests === [], 'Disabled attachment mode must not contact provider.');
$calendar->properties['AttachmentMode'] = 1;
$calendar->properties['AttachmentAllowProvider'] = true;
$beforeAttributes = $calendar->attributes;
$beforeBuffers = $calendar->buffers;
$result = json_decode($calendar->ListProviderAttachments($selector), true, 512, JSON_THROW_ON_ERROR);
attachmentMetadataCheck($result['result'][0]['name'] === $file['name'], 'Real calendar/gateway/provider listing failed.');
attachmentMetadataCheck($calendar->attributes === $beforeAttributes && $calendar->buffers === $beforeBuffers && $calendar->values === [], 'Listing must not publish/cache attachment metadata.');

foreach (['{"eventReference":"evt","CalendarID":"other"}', '{"eventReference":"evt","owner":"forged"}', '{"sourceType":"microsoft-todo","taskId":"task","taskListId":"other"}'] as $invalid) {
    attachmentMetadataReject(fn () => $calendar->ListProviderAttachments($invalid));
}
attachmentMetadataReject(fn () => $calendar->ListProviderAttachments('{"uid":"event","recurrenceId":"not-a-slot"}'));
attachmentMetadataCheck(count($http->requests) === 2, 'Invalid selectors must not contact providers.');

$calendar->properties['MicrosoftTaskListID'] = 'list';
$http->responses = [[200, ['id' => 'completed-task']], [200, ['value' => [$file]]]];
$calendar->ListProviderAttachments('{"sourceType":"microsoft-todo","taskId":"completed-task","taskListId":"list"}');
attachmentMetadataCheck(str_contains($http->requests[2]['url'], '/lists/list/tasks/completed-task?'), 'Completed/reopened task must use its own ID, never successor.');
$taskContent = 'TASK FILE';
$taskFile = array_replace($file, ['id' => 'task-file', 'size' => strlen($taskContent), '@odata.type' => '#microsoft.graph.taskFileAttachment']);
$http->responses = [[200, ['id' => 'completed-task']], [200, $taskFile], [200, $taskContent]];
$taskDownload = json_decode($calendar->DownloadProviderAttachment(json_encode([
    'selector' => ['sourceType' => 'microsoft-todo', 'taskId' => 'completed-task', 'taskListId' => 'list'], 'id' => 'task-file'
], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
attachmentMetadataCheck(base64_decode($taskDownload['content'], true) === $taskContent, 'To Do attachment download routing failed.');

$calendar->properties['AttachmentMode'] = 2;
$calendar->properties['CanWrite'] = true;
$upload = json_encode([
    'selector' => ['eventReference' => 'evt'],
    'name' => 'Proof.pdf',
    'content' => base64_encode("%PDF-1.7\n%%EOF\n")
], JSON_THROW_ON_ERROR);
$http->responses = [[200, ['id' => 'evt']], [201, ['id' => 'uploaded']]];
$uploaded = json_decode($calendar->UploadProviderAttachment($upload), true, 512, JSON_THROW_ON_ERROR);
attachmentMetadataCheck($uploaded['result']['uploaded'] === true && count($http->responses) === 0,
    'Calendar-to-account Microsoft event upload routing failed.');
$taskUpload = json_encode([
    'selector' => ['sourceType' => 'microsoft-todo', 'taskId' => 'task', 'taskListId' => 'list'],
    'name' => 'Proof.pdf',
    'content' => base64_encode("%PDF-1.7\n%%EOF\n")
], JSON_THROW_ON_ERROR);
$http->responses = [[200, ['id' => 'task']], [201, ['id' => 'task-file']]];
$uploaded = json_decode($calendar->UploadProviderAttachment($taskUpload), true, 512, JSON_THROW_ON_ERROR);
attachmentMetadataCheck($uploaded['result']['uploaded'] === true && count($http->responses) === 0,
    'Calendar-to-account Microsoft To Do upload routing failed.');
$calendar->properties['CanWrite'] = false;
$count = count($http->requests);
attachmentMetadataReject(fn () => $calendar->UploadProviderAttachment($upload));
attachmentMetadataCheck(count($http->requests) === $count, 'Read-only calendar must not upload.');
$calendar->properties['CanWrite'] = true;
$calendar->properties['AttachmentMode'] = 1;
attachmentMetadataReject(fn () => $calendar->UploadProviderAttachment($upload));
attachmentMetadataCheck(count($http->requests) === $count, 'Read-only attachment mode must not upload.');
$calendar->properties['AttachmentMode'] = 2;
$http->responses = [[200, ['id' => 'evt']], [201, ['id' => 'uploaded']]];
$calendar->afterReply = static function () use ($calendar): void {
    $calendar->properties['AttachmentAllowProvider'] = false;
};
attachmentMetadataReject(fn () => $calendar->UploadProviderAttachment($upload));
attachmentMetadataCheck(count($http->responses) === 0, 'Post-write revocation test did not reach the provider.');
$calendar->afterReply = null;
$calendar->properties['AttachmentAllowProvider'] = true;

foreach (['policy', 'calendar', 'account', 'taskList'] as $change) {
    $calendar->properties['AttachmentAllowProvider'] = true;
    $calendar->properties['CalendarID'] = 'calendar42';
    $calendar->properties['MicrosoftTaskListID'] = 'list';
    $GLOBALS['localConnections'][8042] = 55;
    $http->responses = [[200, ['id' => 'task']], [200, ['value' => [$file]]]];
    $calendar->afterReply = static function () use ($calendar, $change): void
    {
        match ($change) {
            'policy'   => $calendar->properties['AttachmentAllowProvider'] = false,
            'calendar' => $calendar->properties['CalendarID'] = 'other',
            'account'  => $GLOBALS['localConnections'][8042] = 66,
            'taskList' => $calendar->properties['MicrosoftTaskListID'] = 'other'
        };
    };
    attachmentMetadataReject(fn () => $calendar->ListProviderAttachments('{"sourceType":"microsoft-todo","taskId":"task","taskListId":"list"}'));
}
$calendar->afterReply = null;
$calendar->properties['CalendarID'] = 'calendar42';
$gateway->provider = 2; // Google deferred; no HTTP or fallback to local storage.
$count = count($http->requests);
attachmentMetadataReject(fn () => $calendar->ListProviderAttachments($selector));
attachmentMetadataCheck(count($http->requests) === $count, 'Unsupported provider must not fetch attachments.');
attachmentMetadataReject(fn () => $calendar->UploadProviderAttachment($upload));
attachmentMetadataCheck(count($http->requests) === $count, 'Google must not receive a provider attachment upload.');

$ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:ics-event\r\nDTSTART:20260922T100000Z\r\nDTEND:20260922T110000Z\r\n" .
    "ATTACH;FMTTYPE=application/pdf;ENCODING=BASE64;VALUE=BINARY;FILENAME=ICS.pdf:SUNTIEZJTEU=\r\n" .
    "ATTACH;FILENAME=Reference.pdf:https://private.invalid/document?secret=1\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
$sourceId = hash('sha256', 'ics-file|Fixture');
$gateway->provider = 4;
$gateway->cachedCalendars = json_encode([['id' => $sourceId, 'reference' => 'urn:ips-kalender:ics-subscription:' . $sourceId]], JSON_THROW_ON_ERROR);
$gateway->providerObject = new IPSKalender\ICalendarSubscriptionProvider(
    [['sourceType' => 'file', 'name' => 'Fixture', 'fileData' => base64_encode($ical)]],
    static fn (array $source): IPSKalender\CalendarProviderInterface => new IPSKalender\ICalendarFileProvider(
        (string) $source['fileData'],
        (string) $source['name'],
        (string) $source['id']
    )
);
attachmentMetadataCheck($gateway->providerObject->getAttachmentMetadata(
    'urn:ips-kalender:ics-subscription:' . $sourceId,
    'ics-event'
)[0]['name'] === 'ICS.pdf', 'ICS provider fixture failed.');
$calendar->properties['CalendarID'] = $sourceId;
$result = json_decode($calendar->ListProviderAttachments('{"uid":"ics-event"}'), true, 512, JSON_THROW_ON_ERROR);
attachmentMetadataCheck($result['result'][0]['name'] === 'ICS.pdf'
    && !str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'private.invalid'), 'ICS metadata must be routed without exposing its URI.');
$icsDownload = json_decode($calendar->DownloadProviderAttachment(json_encode([
    'selector' => ['uid' => 'ics-event'], 'id' => $result['result'][0]['id']
], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
attachmentMetadataCheck(base64_decode($icsDownload['content'], true) === 'ICS FILE', 'ICS attachment download routing failed.');
attachmentMetadataReject(fn () => $calendar->DownloadProviderAttachment(json_encode([
    'selector' => ['uid' => 'ics-event'], 'id' => $result['result'][1]['id']
], JSON_THROW_ON_ERROR)));

$xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:response>' .
    '<d:href>/cal/event.ics</d:href><d:propstat><d:prop><d:getetag>"e"</d:getetag><c:calendar-data>' .
    htmlspecialchars($ical, ENT_XML1) . '</c:calendar-data></d:prop></d:propstat></d:response></d:multistatus>';
$davHttp = new AttachmentMetadataHttp([[200, $ical]]);
$gateway->provider = 1;
$gateway->cachedCalendars = '[{"id":"dav42","url":"https://dav.invalid/cal/"}]';
$gateway->providerObject = new IPSKalender\CalDAVProvider($davHttp, 'https://dav.invalid/', new IPSKalender\CalDAVOriginPolicy('https://dav.invalid/'));
$calendar->properties['CalendarID'] = 'dav42';
$calendar->attributes['CachedEvents'] = json_encode([[
    'uid' => 'ics-event', 'resourceUrl' => 'https://dav.invalid/cal/event.ics', 'status' => 'CONFIRMED'
]], JSON_THROW_ON_ERROR);
$result = json_decode($calendar->ListProviderAttachments('{"uid":"ics-event"}'), true, 512, JSON_THROW_ON_ERROR);
attachmentMetadataCheck(
    $result['result'][0]['name'] === 'ICS.pdf'
        && count($davHttp->requests) === 1
        && $davHttp->requests[0]['method'] === 'GET'
        && $davHttp->requests[0]['url'] === 'https://dav.invalid/cal/event.ics',
    'CalDAV metadata must use the synchronized server-side resource.'
);
$davHttp->responses = [[200, $ical]];
$davDownload = json_decode($calendar->DownloadProviderAttachment(json_encode([
    'selector' => ['uid' => 'ics-event'], 'id' => $result['result'][0]['id']
], JSON_THROW_ON_ERROR)), true, 512, JSON_THROW_ON_ERROR);
attachmentMetadataCheck(base64_decode($davDownload['content'], true) === 'ICS FILE', 'CalDAV attachment download routing failed.');

$calendar->attributes['CachedEvents'] = json_encode([[
    'uid' => 'empty-event', 'resourceUrl' => 'https://dav.invalid/cal/empty.ics', 'status' => 'CONFIRMED'
]], JSON_THROW_ON_ERROR);
$davHttp->responses = [[200, str_replace(
    ["UID:ics-event\r\n", "ATTACH;FMTTYPE=application/pdf;ENCODING=BASE64;VALUE=BINARY;FILENAME=ICS.pdf:SUNTIEZJTEU=\r\n", "ATTACH;FILENAME=Reference.pdf:https://private.invalid/document?secret=1\r\n"],
    ["UID:empty-event\r\n", '', ''],
    $ical
)]];
$emptyResult = json_decode($calendar->ListProviderAttachments('{"uid":"empty-event"}'), true, 512, JSON_THROW_ON_ERROR);
attachmentMetadataCheck($emptyResult['result'] === [], 'A CalDAV event without attachments must return an empty result.');

$gateway->provider = 3;
$gateway->cachedCalendars = '[{"id":"calendar42","providerId":"cal","url":"https://graph.microsoft.com/v1.0/me/calendars/cal"}]';
$gateway->providerObject = null;
$calendar->properties['CalendarID'] = 'calendar42';
$http->responses = [[403, ['error' => ['message' => 'PRIVATE_FILENAME.pdf https://private.invalid/?token=secret']]]];
try {
    $calendar->ListProviderAttachments($selector);
    throw new LogicException('Expected private provider failure.');
} catch (IPSKalender\CalendarProviderErrorException $error) {
    attachmentMetadataCheck(!str_contains($error->getMessage(), 'PRIVATE') && !str_contains($error->getMessage(), 'secret'), 'Provider errors must not leak private metadata.');
}
fwrite(STDOUT, "Provider attachment access: Microsoft, ICS and CalDAV routing, scope changes and private errors passed.\n");
