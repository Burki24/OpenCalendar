<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use JsonException;
use ReflectionClass;
use RuntimeException;
use UnexpectedValueException;

/**
 * Loads and serializes Symcon configuration forms for dynamic module forms.
 *
 * Intended for use in classes derived from IPSModule/IPSModuleStrict. The helper
 * resolves the directory of the concrete module class via reflection so a
 * vendored helper can reliably access that module's form.json file.
 *
 * @version 1.0.1
 */
trait ConfigurationFormHelper
{
    /**
     * JSON flags used when serializing configuration forms.
     */
    private const CONFIGURATION_FORM_JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Loads and validates form.json from the directory of the concrete module.
     *
     * The returned associative array can be modified by GetConfigurationForm()
     * before being serialized again with EncodeConfigurationForm().
     *
     * @return array<string,mixed> Decoded configuration form.
     *
     * @throws RuntimeException         If the module path cannot be determined or form.json cannot be read.
     * @throws UnexpectedValueException If form.json contains invalid JSON or its root value is not a JSON object.
     */
    protected function LoadConfigurationForm(): array
    {
        $path = $this->ResolveConfigurationFormPath();
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('The module configuration form could not be read.');
        }

        try {
            $root = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('The module configuration form contains invalid JSON.', 0, $exception);
        }

        if (!is_object($root)) {
            throw new UnexpectedValueException('The module configuration form must contain a JSON object at its root.');
        }

        try {
            $form = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            // The first decode has already validated the same JSON. Keep the
            // exception conversion here to preserve a stable helper contract.
            throw new UnexpectedValueException('The module configuration form contains invalid JSON.', 0, $exception);
        }

        if (!is_array($form)) {
            throw new UnexpectedValueException('The module configuration form could not be decoded as an associative array.');
        }

        return $form;
    }

    /**
     * Serializes a configuration form for the return value of GetConfigurationForm().
     *
     * A configuration form must be a JSON object at its root. An empty PHP array
     * is therefore serialized as an empty JSON object rather than an empty list.
     *
     * @param array<string,mixed> $form Configuration form to serialize.
     *
     * @return string JSON-encoded configuration form.
     *
     * @throws JsonException            If the form cannot be encoded as JSON.
     * @throws UnexpectedValueException If a non-empty list is supplied instead of an object-like array.
     */
    protected function EncodeConfigurationForm(array $form): string
    {
        if ($form !== [] && array_is_list($form)) {
            throw new UnexpectedValueException('The module configuration form must be represented by an associative array.');
        }

        $value = $form === [] ? (object) [] : $form;

        return json_encode($value, self::CONFIGURATION_FORM_JSON_FLAGS);
    }

    /**
     * Sets the visibility of named fields within nested configuration-form items.
     *
     * Unmatched items and other attributes are retained. The input array is not
     * modified, so repeated calls with the same arguments are idempotent.
     *
     * @param list<array<string,mixed>> $items   Form elements or nested items.
     * @param list<string>              $names   Exact field names to update.
     * @param bool                      $visible Visibility assigned to matching fields.
     *
     * @return list<array<string,mixed>> Form items with updated visibility.
     */
    protected function SetFormFieldVisibility(array $items, array $names, bool $visible): array
    {
        foreach ($items as &$item) {
            if (isset($item['name']) && in_array($item['name'], $names, true)) {
                $item['visible'] = $visible;
            }
            if (isset($item['items']) && is_array($item['items'])) {
                $item['items'] = $this->SetFormFieldVisibility($item['items'], $names, $visible);
            }
        }
        unset($item);

        return $items;
    }

    /**
     * Resolves form.json relative to the concrete module class rather than this helper file.
     *
     * @return string Absolute path to the module's form.json.
     *
     * @throws RuntimeException If the concrete module file cannot be determined.
     */
    private function ResolveConfigurationFormPath(): string
    {
        $reflection = new ReflectionClass($this);
        $moduleFile = $reflection->getFileName();
        if (!is_string($moduleFile) || $moduleFile === '') {
            throw new RuntimeException('The module file path could not be determined.');
        }

        return dirname($moduleFile) . DIRECTORY_SEPARATOR . 'form.json';
    }
}
