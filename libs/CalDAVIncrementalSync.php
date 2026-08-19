<?php

declare(strict_types=1);

namespace IPSKalender;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/CalDAVOriginPolicy.php';
require_once __DIR__ . '/CalDAVProvider.php';
require_once __DIR__ . '/CalendarEventRecurrence.php';
require_once __DIR__ . '/ICalendarCodec.php';

/**
 * Retrieves CalDAV collection changes through WebDAV sync tokens when the
 * configured calendar advertises the DAV:sync-collection report.
 */
final class CalDAVIncrementalSync
{
    private const DAV_NAMESPACE = 'DAV:';
    private const MAX_CHANGES = 100_000;

    /**
     * Creates a CalDAV incremental synchronizer inside the account trust boundary.
     */
    public function __construct(
        private readonly CalDAVProvider $provider,
        private readonly CalendarHttpClientInterface $httpClient,
        private readonly CalDAVOriginPolicy $originPolicy
    ) {
    }

    /**
     * Synchronizes one bounded CalDAV event collection.
     *
     * Servers without DAV:sync-collection support transparently keep using the
     * existing bounded full calendar query and return no synchronization token.
     *
     * @return array{items:list<array<string, mixed>>,syncToken:string,incremental:bool}
     */
    public function synchronize(
        string $calendarReference,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $syncToken = ''
    ): array {
        if ($end <= $start) {
            throw new CalDAVProviderException('The event query end must be later than the start.');
        }

        $calendarUrl = $this->trustedAbsoluteUrl($calendarReference);
        $syncToken = trim($syncToken);
        if ($syncToken !== '') {
            return $this->incrementalSync($calendarUrl, $start, $end, $syncToken);
        }

        return $this->fullSync($calendarUrl, $start, $end);
    }

    /**
     * @return array{items:list<array<string, mixed>>,syncToken:string,incremental:bool}
     */
    private function fullSync(
        string $calendarUrl,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        bool $disableIncremental = false
    ): array {
        $syncToken = '';
        if (!$disableIncremental) {
            $syncState = $this->readSyncState($calendarUrl);
            if ($syncState['supported']) {
                $syncToken = $syncState['syncToken'];
            }
        }

        $events = $this->provider->getEvents($calendarUrl, $start, $end);

        return [
            'items'       => $events,
            'syncToken'   => $syncToken,
            'incremental' => false
        ];
    }

    /**
     * @return array{items:list<array<string, mixed>>,syncToken:string,incremental:bool}
     */
    private function incrementalSync(
        string $calendarUrl,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        string $syncToken
    ): array {
        $body = '<?xml version="1.0" encoding="utf-8" ?>'
            . '<d:sync-collection xmlns:d="DAV:">'
            . '<d:sync-token>' . htmlspecialchars($syncToken, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</d:sync-token>'
            . '<d:sync-level>1</d:sync-level>'
            . '<d:prop><d:getetag/></d:prop>'
            . '</d:sync-collection>';
        $response = $this->httpClient->request(
            'REPORT',
            $calendarUrl,
            [
                'Accept'       => 'application/xml, text/xml',
                'Content-Type' => 'application/xml; charset=utf-8',
                'Depth'        => '0'
            ],
            $body
        );

        if ($this->isInvalidSyncTokenResponse($response)) {
            return $this->fullSync($calendarUrl, $start, $end);
        }
        if (in_array($response->statusCode, [405, 501], true)) {
            return $this->fullSync($calendarUrl, $start, $end, true);
        }
        $this->assertCollectionResponse($response, [207], 'calendar synchronization');
        $effectiveCalendarUrl = $this->trustedEffectiveUrl($response, $calendarUrl);

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $nextSyncToken = $this->firstNodeValue($xpath, '/d:multistatus/d:sync-token');
        if ($nextSyncToken === '') {
            throw new CalDAVProviderException('CalDAV did not return a synchronization token.');
        }

        $changes = [];
        $responses = $xpath->query('/d:multistatus/d:response');
        if ($responses === false) {
            throw new CalDAVProviderException('CalDAV returned an invalid synchronization response.');
        }

        foreach ($responses as $resourceResponse) {
            if (!$resourceResponse instanceof DOMElement) {
                continue;
            }
            $href = $this->firstNodeValue($xpath, './d:href', $resourceResponse);
            if ($href === '') {
                throw new CalDAVProviderException('CalDAV returned a synchronization entry without a resource URL.');
            }
            $resourceUrl = $this->resolveResourceUrl($calendarUrl, $effectiveCalendarUrl, $href);
            $statusCode = $this->resourceStatusCode($xpath, $resourceResponse);
            if (in_array($statusCode, [404, 410], true)) {
                $changes[] = $this->deletionMarker($resourceUrl);
            } elseif ($statusCode === 0 || $statusCode === 200) {
                array_push(
                    $changes,
                    ...$this->resourceChanges($calendarUrl, $resourceUrl, $start, $end)
                );
            } else {
                throw new CalDAVProviderException(
                    sprintf('CalDAV returned HTTP %d for a synchronized resource.', $statusCode),
                    $statusCode
                );
            }
            if (count($changes) > self::MAX_CHANGES) {
                throw new CalDAVProviderException('CalDAV returned too many event changes.');
            }
        }

        return [
            'items'       => $changes,
            'syncToken'   => $nextSyncToken,
            'incremental' => true
        ];
    }

    /**
     * @return array{supported:bool,syncToken:string}
     */
    private function readSyncState(string $calendarUrl): array
    {
        $body = '<?xml version="1.0" encoding="utf-8" ?>'
            . '<d:propfind xmlns:d="DAV:"><d:prop>'
            . '<d:sync-token/><d:supported-report-set/>'
            . '</d:prop></d:propfind>';
        $response = $this->httpClient->request(
            'PROPFIND',
            $calendarUrl,
            [
                'Accept'       => 'application/xml, text/xml',
                'Content-Type' => 'application/xml; charset=utf-8',
                'Depth'        => '0'
            ],
            $body
        );

        if (in_array($response->statusCode, [405, 501], true)) {
            return ['supported' => false, 'syncToken' => ''];
        }
        $this->assertCollectionResponse($response, [207], 'synchronization capability discovery');
        $this->trustedEffectiveUrl($response, $calendarUrl);

        $document = $this->parseXml($response->body);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('d', self::DAV_NAMESPACE);
        $syncToken = $this->firstNodeValue($xpath, '//d:sync-token');
        $reportNodes = $xpath->query(
            '//d:supported-report-set/d:supported-report/d:report/d:sync-collection'
        );
        $supported = $syncToken !== '' && $reportNodes !== false && $reportNodes->length > 0;

        return [
            'supported' => $supported,
            'syncToken' => $supported ? $syncToken : ''
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resourceChanges(
        string $calendarUrl,
        string $resourceUrl,
        DateTimeImmutable $start,
        DateTimeImmutable $end
    ): array {
        $response = $this->httpClient->request('GET', $resourceUrl, ['Accept' => 'text/calendar']);
        if (in_array($response->statusCode, [404, 410], true)) {
            return [$this->deletionMarker($resourceUrl)];
        }
        $this->assertCollectionResponse($response, [200], 'changed calendar object retrieval');
        $effectiveResourceUrl = $this->trustedEffectiveUrl($response, $resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $effectiveResourceUrl);
        $etag = trim((string) ($response->headers['etag'] ?? ''));
        $events = $this->enableExpandedOccurrenceWrites(
            ICalendarCodec::parseEventsInRange(
                $response->body,
                $resourceUrl,
                $etag,
                $start,
                $end
            )
        );
        if ($events === []) {
            return [$this->deletionMarker($resourceUrl)];
        }

        foreach ($events as &$event) {
            $event['_syncReplaceResource'] = true;
        }
        unset($event);

        return $events;
    }

    /**
     * Marks locally expanded recurrence instances like the regular CalDAV provider.
     *
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private function enableExpandedOccurrenceWrites(array $events): array
    {
        $groups = [];
        foreach ($events as $index => $event) {
            $uid = trim((string) ($event['uid'] ?? ''));
            if ($uid !== '') {
                $groups[$uid][] = $index;
            }
        }

        foreach ($groups as $uid => $indexes) {
            $expandedRecurring = count($indexes) > 1;
            foreach ($indexes as $index) {
                if (trim((string) ($events[$index]['recurrenceId'] ?? '')) !== '') {
                    $expandedRecurring = true;
                    break;
                }
            }
            if (!$expandedRecurring) {
                continue;
            }

            foreach ($indexes as $index) {
                $originalStart = trim((string) ($events[$index]['originalStart'] ?? ''));
                if ($originalStart === '') {
                    $originalStart = trim((string) ($events[$index]['start'] ?? ''));
                }
                if ($originalStart === '') {
                    continue;
                }
                $recurrenceId = trim((string) ($events[$index]['recurrenceId'] ?? ''));
                $events[$index] = array_merge(
                    $events[$index],
                    CalendarEventRecurrence::occurrence(
                        $uid,
                        $uid . '|' . ($recurrenceId !== '' ? $recurrenceId : $originalStart),
                        $originalStart,
                        $recurrenceId,
                        true,
                        false,
                        true,
                        true,
                        true
                    )
                );
            }
        }

        return $events;
    }

    /**
     * @return array{_syncDeleted:bool,resourceUrl:string}
     */
    private function deletionMarker(string $resourceUrl): array
    {
        return [
            '_syncDeleted' => true,
            'resourceUrl'  => $resourceUrl
        ];
    }

    private function resourceStatusCode(DOMXPath $xpath, DOMElement $response): int
    {
        $status = $this->firstNodeValue($xpath, './d:status', $response);
        if ($status !== '') {
            return $this->httpStatusCode($status);
        }

        $propstats = $xpath->query('./d:propstat', $response);
        if ($propstats === false) {
            return 0;
        }
        foreach ($propstats as $propstat) {
            if (!$propstat instanceof DOMElement) {
                continue;
            }
            $status = $this->httpStatusCode($this->firstNodeValue($xpath, './d:status', $propstat));
            if ($status === 200) {
                return 200;
            }
        }

        return 0;
    }

    private function httpStatusCode(string $status): int
    {
        return preg_match('/\s(\d{3})(?:\s|$)/', trim($status), $matches) === 1
            ? (int) $matches[1]
            : 0;
    }

    private function isInvalidSyncTokenResponse(CalendarHttpResponse $response): bool
    {
        return $response->statusCode === 410
            || ($response->statusCode === 403 && stripos($response->body, 'valid-sync-token') !== false);
    }

    /**
     * @param list<int> $expectedStatusCodes
     */
    private function assertCollectionResponse(
        CalendarHttpResponse $response,
        array $expectedStatusCodes,
        string $operation
    ): void {
        if (in_array($response->statusCode, [401, 403], true)) {
            throw new CalDAVProviderException(
                'Authentication failed or calendar access was denied.',
                $response->statusCode
            );
        }
        if (!in_array($response->statusCode, $expectedStatusCodes, true)) {
            throw new CalDAVProviderException(
                sprintf('Unexpected CalDAV response during %s: HTTP %d.', $operation, $response->statusCode),
                $response->statusCode
            );
        }
    }

    private function resolveResourceUrl(string $calendarUrl, string $baseUrl, string $href): string
    {
        try {
            $resourceUrl = $this->originPolicy->resolveUrl($baseUrl, $href);
        } catch (InvalidArgumentException) {
            throw new CalDAVProviderException('Could not resolve a CalDAV URL.');
        }
        $resourceUrl = $this->trustedAbsoluteUrl($resourceUrl);
        $this->assertResourceBelongsToCalendar($calendarUrl, $resourceUrl);

        return $resourceUrl;
    }

    private function trustedEffectiveUrl(CalendarHttpResponse $response, string $requestedUrl): string
    {
        $effectiveUrl = trim($response->effectiveUrl) !== '' ? $response->effectiveUrl : $requestedUrl;

        return $this->trustedAbsoluteUrl($effectiveUrl);
    }

    private function trustedAbsoluteUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if ($parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || !$this->originPolicy->isAllowedUrl($url)) {
            throw new CalDAVProviderException('The CalDAV resource URL belongs to an untrusted origin.');
        }

        return $url;
    }

    private function assertResourceBelongsToCalendar(string $calendarUrl, string $resourceUrl): void
    {
        $calendar = parse_url($calendarUrl);
        $resource = parse_url($resourceUrl);
        if ($calendar === false || $resource === false) {
            throw new CalDAVProviderException('The CalDAV resource URL is invalid.');
        }

        $calendarPort = $calendar['port']
            ?? (strtolower((string) ($calendar['scheme'] ?? '')) === 'https' ? 443 : 80);
        $resourcePort = $resource['port']
            ?? (strtolower((string) ($resource['scheme'] ?? '')) === 'https' ? 443 : 80);
        $calendarPath = rtrim($this->normalizePath((string) ($calendar['path'] ?? '/')), '/') . '/';
        $resourcePath = $this->normalizePath((string) ($resource['path'] ?? '/'));

        if (strcasecmp((string) ($calendar['scheme'] ?? ''), (string) ($resource['scheme'] ?? '')) !== 0
            || strcasecmp((string) ($calendar['host'] ?? ''), (string) ($resource['host'] ?? '')) !== 0
            || $calendarPort !== $resourcePort
            || !str_starts_with($resourcePath, $calendarPath)) {
            throw new CalDAVProviderException('The event resource does not belong to the configured calendar.');
        }
    }

    private function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments) . (str_ends_with($path, '/') ? '/' : '');
    }

    private function parseXml(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new CalDAVProviderException('The CalDAV server returned invalid XML.');
        }

        return $document;
    }

    private function firstNodeValue(
        DOMXPath $xpath,
        string $expression,
        ?DOMNode $contextNode = null
    ): string {
        $nodes = $xpath->query($expression, $contextNode);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return trim((string) $nodes->item(0)?->textContent);
    }
}
