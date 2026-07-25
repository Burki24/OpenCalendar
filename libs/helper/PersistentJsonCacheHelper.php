<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use JsonException;
use UnexpectedValueException;

/**
 * Provides persistent JSON-backed array caches using Symcon module attributes.
 *
 * Intended for use in classes derived from IPSModule/IPSModuleStrict. Attributes
 * are persistent and module-internal, which makes them suitable for cached API
 * data and similar structured state that should not be exposed as a status
 * variable.
 *
 * @version 1.0.0
 */
trait PersistentJsonCacheHelper
{
    /**
     * JSON flags used for all cache serialization.
     */
    private const PERSISTENT_JSON_CACHE_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Registers a persistent JSON cache as a Symcon string attribute.
     *
     * This should normally be called from Create(). Calling it again is safe;
     * Symcon keeps the existing attribute value.
     *
     * @param string               $name    Attribute name.
     * @param array<array-key,mixed> $default Initial value for a new attribute.
     *
     * @throws JsonException If the default value cannot be encoded as JSON.
     */
    protected function RegisterPersistentJsonCache(string $name, array $default = []): void
    {
        $this->RegisterAttributeString($name, $this->EncodePersistentJsonCache($default));
    }

    /**
     * Reads a persistent JSON cache.
     *
     * @param string $name Attribute name.
     *
     * @return array<array-key,mixed>
     *
     * @throws UnexpectedValueException If the stored attribute is not valid JSON
     *                                  or the decoded value is not an array.
     */
    protected function ReadPersistentJsonCache(string $name): array
    {
        $raw = $this->ReadAttributeString($name);

        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                sprintf('Persistent JSON cache "%s" contains invalid JSON.', $name),
                0,
                $exception
            );
        }

        if (!is_array($value)) {
            throw new UnexpectedValueException(
                sprintf('Persistent JSON cache "%s" must contain a JSON array or object.', $name)
            );
        }

        return $value;
    }

    /**
     * Writes a persistent JSON cache only when the serialized content changed.
     *
     * @param string                 $name Attribute name.
     * @param array<array-key,mixed> $data Value to persist.
     *
     * @return bool True when the attribute was changed, false when the serialized
     *              value was already identical.
     *
     * @throws JsonException If the supplied value cannot be encoded as JSON.
     */
    protected function WritePersistentJsonCache(string $name, array $data): bool
    {
        $encoded = $this->EncodePersistentJsonCache($data);

        if ($this->ReadAttributeString($name) === $encoded) {
            return false;
        }

        $this->WriteAttributeString($name, $encoded);

        return true;
    }

    /**
     * Resets a persistent JSON cache to an empty array.
     *
     * @param string $name Attribute name.
     *
     * @return bool True when the attribute changed, false when it was already empty.
     *
     * @throws JsonException If the empty value unexpectedly cannot be encoded.
     */
    protected function ClearPersistentJsonCache(string $name): bool
    {
        return $this->WritePersistentJsonCache($name, []);
    }

    /**
     * Encodes a cache value consistently.
     *
     * @param array<array-key,mixed> $data
     *
     * @throws JsonException If the supplied value cannot be encoded as JSON.
     */
    private function EncodePersistentJsonCache(array $data): string
    {
        return json_encode($data, self::PERSISTENT_JSON_CACHE_FLAGS);
    }
}
