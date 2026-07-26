<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * Provides reusable JSON encoding and decoding for Symcon data-flow messages.
 *
 * The helper deliberately handles only the transport envelope: a JSON object
 * with a non-empty DataID plus arbitrary payload fields. Sending data to a
 * parent or child and translating transport failures remain responsibilities
 * of the calling module.
 *
 * @version 1.0.0
 */
trait DataFlowHelper
{
    private const DATA_FLOW_JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Encodes a Symcon data-flow message with one authoritative DataID.
     *
     * @param string               $dataID  Non-empty data-flow identifier.
     * @param array<string|int,mixed> $payload Additional transport fields without DataID.
     *
     * @return string JSON-encoded data-flow message.
     *
     * @throws InvalidArgumentException If the DataID is empty or the payload defines DataID itself.
     * @throws JsonException If the payload cannot be encoded as JSON.
     */
    protected function EncodeDataFlowMessage(string $dataID, array $payload = []): string
    {
        $this->AssertDataFlowID($dataID);

        foreach (array_keys($payload) as $key) {
            if (is_string($key) && strcasecmp($key, 'DataID') === 0) {
                throw new InvalidArgumentException('The data-flow payload must not define DataID.');
            }
        }

        return json_encode(
            ['DataID' => $dataID] + $payload,
            self::DATA_FLOW_JSON_FLAGS
        );
    }

    /**
     * Decodes and validates a Symcon data-flow message.
     *
     * The JSON root must be an object and contain a non-empty string DataID.
     * When an expected DataID is supplied, the comparison is case-insensitive.
     * Nested JSON objects are returned as associative arrays.
     *
     * @param string      $json           JSON-encoded data-flow message.
     * @param string|null $expectedDataID Optional DataID expected by the receiving module.
     *
     * @return array<string,mixed> Decoded data-flow message including DataID.
     *
     * @throws InvalidArgumentException If the root is not a JSON object or an explicitly expected DataID is empty.
     * @throws JsonException If the message is not valid JSON.
     * @throws UnexpectedValueException If DataID is invalid or an unexpected DataID is received.
     */
    protected function DecodeDataFlowMessage(string $json, ?string $expectedDataID = null): array
    {
        if ($expectedDataID !== null) {
            $this->AssertDataFlowID($expectedDataID);
        }

        $root = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$root instanceof \stdClass) {
            throw new InvalidArgumentException('The data-flow message must be a JSON object.');
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new InvalidArgumentException('The data-flow message could not be decoded as an object.');
        }

        $actualDataID = $data['DataID'] ?? null;
        if (!is_string($actualDataID) || trim($actualDataID) === '') {
            throw new UnexpectedValueException('The data-flow message must contain a non-empty string DataID.');
        }

        if ($expectedDataID !== null && strcasecmp($actualDataID, $expectedDataID) !== 0) {
            throw new UnexpectedValueException('The data-flow message uses an unexpected DataID.');
        }

        return $data;
    }

    /**
     * Validates a data-flow identifier supplied by the calling module.
     *
     * @param string $dataID Data-flow identifier to validate.
     *
     * @return void No return value.
     *
     * @throws InvalidArgumentException If the identifier is empty.
     */
    private function AssertDataFlowID(string $dataID): void
    {
        if (trim($dataID) === '') {
            throw new InvalidArgumentException('DataID must not be empty.');
        }
    }
}
