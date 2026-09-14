<?php

declare(strict_types=1);

namespace IPSKalender;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use UnexpectedValueException;

require_once __DIR__ . '/CalendarHttpClient.php';
require_once __DIR__ . '/ICalendarCodec.php';

/**
 * In-process resource adapter for the existing iCalendar writer; never sends HTTP.
 * Mutations are tentative until the owning module atomically saves the snapshot.
 */
final class LocalCalendarResourceStore implements CalendarHttpClientInterface
{
    private const MAX_BYTES = 16_777_216;

    /** @param array<string, string> $resources Original iCalendar resources. */
    public function __construct(private array $resources, private readonly string $calendarReference)
    {
        if (preg_match('~^https://opencalendar\.invalid/local/[1-9][0-9]*/$~D', $calendarReference) !== 1) {
            throw new InvalidArgumentException('The local calendar identity is invalid.');
        }
        foreach ($resources as $url => $ical) {
            if (!is_string($url) || !$this->isResource($url) || !is_string($ical)
                || !str_starts_with($ical, 'BEGIN:VCALENDAR') || !str_contains($ical, 'END:VCALENDAR')) {
                throw new UnexpectedValueException('Local calendar original data is invalid.');
            }
        }
        $this->assertSize($resources);
        foreach ($resources as $url => $ical) {
            $events = ICalendarCodec::parseEvents($ical, $url, '');
            if ($events === [] || count($events) !== substr_count($ical, 'BEGIN:VEVENT')) {
                throw new UnexpectedValueException('Local calendar original data is invalid.');
            }
        }
    }

    /** @return array<string, string> Current tentative resource snapshot. */
    public function exportResources(): array
    {
        return $this->resources;
    }

    /** Returns the strong content validator, or an empty string for a missing resource. */
    public function etag(string $resource): string
    {
        return isset($this->resources[$resource]) ? '"' . hash('sha256', $this->resources[$resource]) . '"' : '';
    }

    /** @inheritDoc */
    public function request(string $method, string $url, array $headers = [], string $body = '', int $maxResponseBytes = 67_108_864): CalendarHttpResponse
    {
        $method = strtoupper($method);
        if ($method === 'REPORT' && hash_equals($this->calendarReference, $url)) {
            return $this->lookup($body, $maxResponseBytes);
        }
        if (!$this->isResource($url)) {
            throw new InvalidArgumentException('The local resource does not belong to this calendar.');
        }
        $exists = isset($this->resources[$url]);
        $etag = $this->etag($url);
        $headers = array_change_key_case($headers, CASE_LOWER);
        if ($method === 'GET') {
            $ical = $this->resources[$url] ?? '';
            if (strlen($ical) > $maxResponseBytes) {
                throw new UnexpectedValueException('The local resource exceeds the response size limit.');
            }
            return new CalendarHttpResponse($exists ? 200 : 404, ['etag' => $etag], $ical, $url);
        }
        if (!in_array($method, ['PUT', 'DELETE'], true)) {
            throw new InvalidArgumentException('Unsupported local resource operation.');
        }
        if (($headers['if-none-match'] ?? '') === '*' && $exists
            || isset($headers['if-match']) && (!$exists || !hash_equals($etag, $headers['if-match']))) {
            return new CalendarHttpResponse(412, [], '', $url);
        }
        if ($method === 'DELETE') {
            unset($this->resources[$url]);
            return new CalendarHttpResponse($exists ? 204 : 404, [], '', $url);
        }
        if (!str_starts_with($body, 'BEGIN:VCALENDAR') || !str_contains($body, 'END:VCALENDAR')
            || ICalendarCodec::parseEvents($body, $url, '') === []) {
            throw new UnexpectedValueException('The local calendar resource is invalid.');
        }
        $next = $this->resources;
        $next[$url] = $body;
        $this->assertSize($next);
        $this->resources = $next;
        return new CalendarHttpResponse($exists ? 204 : 201, ['etag' => $this->etag($url)], '', $url);
    }

    private function isResource(string $url): bool
    {
        if (!str_starts_with($url, $this->calendarReference)) {
            return false;
        }
        $name = substr($url, strlen($this->calendarReference));
        return preg_match('~^[A-Za-z0-9_.%+-]+\.ics$~D', $name) === 1
            && !str_contains(rawurldecode($name), '/') && !str_contains(rawurldecode($name), '\\');
    }

    /** @param array<string, string> $resources */
    private function assertSize(array $resources): void
    {
        $size = 0;
        foreach ($resources as $resource) {
            $size += strlen($resource);
        }
        if ($size > self::MAX_BYTES) {
            throw new UnexpectedValueException('Local calendar storage exceeds 16 MiB.');
        }
    }

    private function lookup(string $body, int $maxResponseBytes): CalendarHttpResponse
    {
        $document = new DOMDocument();
        if (stripos($body, '<!DOCTYPE') !== false || !$document->loadXML($body, LIBXML_NONET)) {
            throw new InvalidArgumentException('Invalid local resource lookup.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        $uid = $xpath->evaluate('string(//c:prop-filter[@name="UID"]/c:text-match)');
        if (!is_string($uid) || $uid === '') {
            throw new InvalidArgumentException('Local resource lookup requires an event UID.');
        }
        $xml = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">';
        $found = false;
        foreach ($this->resources as $url => $ical) {
            foreach (ICalendarCodec::parseEvents($ical, $url, '') as $event) {
                if (($event['uid'] ?? '') !== $uid) {
                    continue;
                }
                $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $xml .= '<d:response><d:href>' . $escape($url) . '</d:href><d:propstat><d:prop><d:getetag>'
                    . $escape($this->etag($url)) . '</d:getetag><c:calendar-data>' . $escape($ical)
                    . '</c:calendar-data></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';
                $found = true;
                break;
            }
        }
        $xml .= '</d:multistatus>';
        if (strlen($xml) > $maxResponseBytes) {
            throw new UnexpectedValueException('The local resource exceeds the response size limit.');
        }
        // Explicit missing identity allows pending-task recovery to release the series.
        return new CalendarHttpResponse($found ? 207 : 404, [], $xml, $this->calendarReference);
    }
}
