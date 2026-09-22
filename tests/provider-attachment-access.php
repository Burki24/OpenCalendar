<?php

declare(strict_types=1);

require_once __DIR__ . '/local-calendar-module.php';
require_once __DIR__ . '/microsoft-attachment-metadata.php';
require_once __DIR__ . '/../Kalender Konto/traits/ChildGatewayTrait.php';

final class AttachmentMetadataGateway
{
    use KalenderKontoChildGatewayTrait;
    use Burki24\SymconModuleHelper\DataFlowHelper;

    private const PROVIDER_MICROSOFT = 3;
    private const PROVIDER_ICS = 4;
    private const DATA_ID_FROM_CHILD = '{4E535B1D-69C7-AC77-1372-0282B21BAEC9}';
    public int $provider = 3;

    public function __construct(public AttachmentMetadataHttp $http)
    {
    }

    private function ReadPropertyInteger(string $name): int
    {
        return $this->provider;
    }

    private function ReadAttributeString(string $name): string
    {
        return '[{"id":"calendar42","providerId":"cal","url":"https://graph.microsoft.com/v1.0/me/calendars/cal"}]';
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
attachmentMetadataCheck(count($http->requests) === 2, 'Invalid selectors must not contact providers.');

$calendar->properties['MicrosoftTaskListID'] = 'list';
$http->responses = [[200, ['id' => 'completed-task']], [200, ['value' => [$file]]]];
$calendar->ListProviderAttachments('{"sourceType":"microsoft-todo","taskId":"completed-task","taskListId":"list"}');
attachmentMetadataCheck(str_contains($http->requests[2]['url'], '/lists/list/tasks/completed-task?'), 'Completed/reopened task must use its own ID, never successor.');

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
$gateway->provider = 3;
$http->responses = [[403, ['error' => ['message' => 'PRIVATE_FILENAME.pdf https://private.invalid/?token=secret']]]];
try {
    $calendar->ListProviderAttachments($selector);
    throw new LogicException('Expected private provider failure.');
} catch (IPSKalender\CalendarProviderErrorException $error) {
    attachmentMetadataCheck(!str_contains($error->getMessage(), 'PRIVATE') && !str_contains($error->getMessage(), 'secret'), 'Provider errors must not leak private metadata.');
}
fwrite(STDOUT, "Provider attachment access: real calendar/gateway/provider flow, disabled state, scope changes and private errors passed.\n");
