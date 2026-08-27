<?php

declare(strict_types=1);

namespace IPSKalender;

use JsonException;

require_once __DIR__ . '/CalendarHttpClient.php';

/**
 * Records a compact, privacy-conscious summary of Microsoft calendarView responses.
 *
 * The wrapped response is returned unchanged. Diagnostics deliberately exclude
 * authorization headers, response bodies, URLs, and complete provider IDs.
 */
final class MicrosoftCalendarDebugHttpClient implements CalendarHttpClientInterface
{
    private const MAX_RECURRING_SAMPLES = 50;

    /** @var array<string, int> */
    private array $statusCodes = [];

    /** @var array<string, int> */
    private array $typeCounts = [];

    /** @var list<array<string, mixed>> */
    private array $recurringSamples = [];

    private int $requestCount = 0;
    private int $pageCount = 0;
    private int $rawItemCount = 0;
    private int $eligibleItemCount = 0;
    private int $cancelledCount = 0;
    private int $invalidItemCount = 0;
    private int $missingIdCount = 0;
    private int $missingStartCount = 0;
    private int $nextLinkCount = 0;
    private int $decodeErrorCount = 0;

    /**
     * Wraps the Microsoft Graph HTTP client without changing request behavior.
     */
    public function __construct(private readonly CalendarHttpClientInterface $innerClient)
    {
    }

    /** @inheritDoc */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
        int $maxResponseBytes = 67_108_864
    ): CalendarHttpResponse {
        $response = $this->innerClient->request($method, $url, $headers, $body, $maxResponseBytes);

        if ($this->isCalendarViewRequest($method, $url)) {
            $this->captureCalendarViewResponse($response);
        }

        return $response;
    }

    /**
     * Returns the accumulated calendarView diagnostics for the current provider request.
     *
     * @return array<string, mixed>
     */
    public function diagnostics(): array
    {
        $statusCodes = $this->statusCodes;
        $typeCounts = $this->typeCounts;
        ksort($statusCodes, SORT_NATURAL);
        ksort($typeCounts, SORT_NATURAL);

        return [
            'requestCount'      => $this->requestCount,
            'pageCount'         => $this->pageCount,
            'statusCodes'       => $statusCodes,
            'rawItemCount'      => $this->rawItemCount,
            'eligibleItemCount' => $this->eligibleItemCount,
            'cancelledCount'    => $this->cancelledCount,
            'invalidItemCount'  => $this->invalidItemCount,
            'missingIdCount'    => $this->missingIdCount,
            'missingStartCount' => $this->missingStartCount,
            'typeCounts'        => $typeCounts,
            'nextLinkCount'     => $this->nextLinkCount,
            'decodeErrorCount'  => $this->decodeErrorCount,
            'recurringSamples'  => $this->recurringSamples
        ];
    }

    private function isCalendarViewRequest(string $method, string $url): bool
    {
        return strtoupper($method) === 'GET'
            && str_starts_with($url, 'https://graph.microsoft.com/')
            && preg_match('~/calendarView(?:\\?|$)~', $url) === 1;
    }

    private function captureCalendarViewResponse(CalendarHttpResponse $response): void
    {
        ++$this->requestCount;
        ++$this->pageCount;
        $status = (string) $response->statusCode;
        $this->statusCodes[$status] = ($this->statusCodes[$status] ?? 0) + 1;

        try {
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            ++$this->decodeErrorCount;
            return;
        }
        if (!is_array($data)) {
            ++$this->decodeErrorCount;
            return;
        }

        if (trim((string) ($data['@odata.nextLink'] ?? '')) !== '') {
            ++$this->nextLinkCount;
        }

        $items = $data['value'] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            ++$this->decodeErrorCount;
            return;
        }

        foreach ($items as $item) {
            ++$this->rawItemCount;
            if (!is_array($item) || array_is_list($item)) {
                ++$this->invalidItemCount;
                continue;
            }

            $type = strtolower(trim((string) ($item['type'] ?? '')));
            if ($type === '') {
                $type = 'unknown';
            }
            $this->typeCounts[$type] = ($this->typeCounts[$type] ?? 0) + 1;

            if ((bool) ($item['isCancelled'] ?? false)) {
                ++$this->cancelledCount;
            }

            $eventId = trim((string) ($item['id'] ?? ''));
            $start = is_array($item['start'] ?? null) ? $item['start'] : [];
            if ($eventId === '') {
                ++$this->missingIdCount;
            }
            if ($start === []) {
                ++$this->missingStartCount;
            }
            if (!(bool) ($item['isCancelled'] ?? false) && $eventId !== '' && $start !== []) {
                ++$this->eligibleItemCount;
            }

            $seriesMasterId = trim((string) ($item['seriesMasterId'] ?? ''));
            $recurring = in_array($type, ['seriesmaster', 'occurrence', 'exception'], true)
                || $seriesMasterId !== ''
                || is_array($item['recurrence'] ?? null);
            if (!$recurring || count($this->recurringSamples) >= self::MAX_RECURRING_SAMPLES) {
                continue;
            }

            $this->recurringSamples[] = [
                'summary'          => $this->debugSummary((string) ($item['subject'] ?? '')),
                'type'             => $type,
                'cancelled'        => (bool) ($item['isCancelled'] ?? false),
                'start'            => trim((string) ($start['dateTime'] ?? '')),
                'startTimeZone'    => trim((string) ($start['timeZone'] ?? '')),
                'eventIdHash'      => $this->debugReferenceHash($eventId),
                'seriesMasterHash' => $this->debugReferenceHash($seriesMasterId)
            ];
        }
    }

    private function debugReferenceHash(string $value): string
    {
        $value = trim($value);
        return $value === '' ? '' : substr(hash('sha256', $value), 0, 12);
    }

    private function debugSummary(string $summary): string
    {
        $summary = trim((string) preg_replace('/\\s+/u', ' ', $summary));
        if ($summary === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($summary, 0, 80, 'UTF-8')
            : substr($summary, 0, 80);
    }
}
