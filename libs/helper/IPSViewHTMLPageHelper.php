<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use InvalidArgumentException;
use JsonException;
use ReflectionClass;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/HelperTranslationHelper.php';

/**
 * Renders native and IPSView HTML pages through one shared asset contract.
 *
 * Consumers provide the module-specific HTML structure in index.html and the
 * visual implementation in style.css/app.js. The helper owns asset loading,
 * bootstrap encoding, page metadata, fixed placeholders and validation.
 *
 * @version 1.3.0
 */
trait IPSViewHTMLPageHelper
{
    use HelperTranslationHelper;

    public const IPSVIEW_HTML_CONTRACT_VERSION = 1;

    private const IPSVIEW_HTML_ENABLE_PROPERTY = 'EnableIPSView';
    private const IPSVIEW_HTML_VARIABLE_REGISTRY_ATTRIBUTE = 'IPSViewHTMLVariableRegistry';
    private const IPSVIEW_HTML_FORM_MARKER = 'Configure optional IPSView HTML output.';
    private const IPSVIEW_HTML_DELETE_ACTION = 'IPSViewHTMLDeleteVariables';

    /** @var array<string,string> */
    private const IPSVIEW_HTML_TRANSLATION_SOURCES = [
        'field.enable_ipsview'           => 'Provide IPSView HTML output',
        'description.enable_ipsview'     => 'When enabled, the module creates additional String variables with the WebContent presentation for IPSView. Native Symcon tile views remain available separately. When disabled, existing IPSView variables are retained until the user explicitly deletes them.',
        'description.retained_variables' => 'IPSView output is disabled. Existing IPSView variables are retained and are no longer updated.',
        'action.delete_variables'        => 'Delete retained IPSView variables...',
        'popup.delete_caption'           => 'Delete IPSView variables?',
        'popup.delete_description'       => 'The following IPSView variables will be deleted permanently. Existing links and placements that reference them will no longer work.',
        'action.keep_variables'          => 'Keep variables',
        'action.confirm_delete'          => 'Delete variables',
        'message.variables_deleted'      => 'The retained IPSView variables were deleted.'
    ];

    /** @var list<string> */
    private const IPSVIEW_HTML_REQUIRED_PLACEHOLDERS = [
        '{{HTML_LANGUAGE}}',
        '{{HTML_CLASSES}}',
        '{{VIEWPORT_CONTENT}}',
        '{{ROOT_FONT_SIZE}}',
        '{{VISUALIZATION_THEME}}',
        '{{MODULE_STYLE}}',
        '{{IPSVIEW_STYLE}}',
        '{{BOOTSTRAP_JSON}}',
        '{{MODULE_SCRIPT}}'
    ];

    /** @var list<string> */
    private const IPSVIEW_HTML_CONFIGURATION_KEYS = [
        'templateAsset',
        'styleAsset',
        'scriptAsset',
        'language',
        'classes',
        'viewport',
        'rootFontSize',
        'title',
        'visualizationTheme',
        'ipsViewStyle',
        'state',
        'runtime',
        'translations',
        'options',
        'replacements'
    ];

    /** Registers the common switch and variable registry for optional IPSView HTML output. */
    protected function RegisterIPSViewHTMLPageProperties(): void
    {
        $this->RegisterPropertyBoolean(self::IPSVIEW_HTML_ENABLE_PROPERTY, false);
        $this->RegisterAttributeString(self::IPSVIEW_HTML_VARIABLE_REGISTRY_ATTRIBUTE, '[]');
    }

    /**
     * Handles helper-owned configuration form actions.
     *
     * Consumers should call this method at the beginning of RequestAction() and
     * return immediately when it reports a handled action.
     */
    protected function HandleIPSViewHTMLPageAction(string $ident, mixed $value): bool
    {
        if ($ident !== self::IPSVIEW_HTML_DELETE_ACTION) {
            return false;
        }

        $this->DeleteRetainedIPSViewHTMLVariables();
        return true;
    }

    /**
     * Returns the common configuration-form controls for optional IPSView output.
     *
     * A module-specific description can replace the generic helper hint. The
     * checkbox caption always remains helper-owned and centrally translated.
     *
     * @return array<int,array<string,mixed>> Symcon configuration-form items.
     */
    protected function IPSViewHTMLPageFormItems(string $description = ''): array
    {
        $description = trim($description);
        if ($description === '') {
            $description = $this->IPSViewHTMLPageText('description.enable_ipsview');
        }

        $items = [
            [
                'type'    => 'CheckBox',
                'name'    => self::IPSVIEW_HTML_ENABLE_PROPERTY,
                'caption' => $this->IPSViewHTMLPageText('field.enable_ipsview')
            ],
            [
                'type'    => 'Label',
                'caption' => $description
            ]
        ];

        $retainedVariables = $this->IPSViewHTMLRetainedVariables();
        if ($this->IsIPSViewHTMLPageEnabled() || $retainedVariables === []) {
            return $items;
        }

        $items[] = [
            'type'    => 'Label',
            'caption' => $this->IPSViewHTMLPageText('description.retained_variables')
        ];
        $items[] = $this->IPSViewHTMLDeleteVariablesPopup($retainedVariables);

        return $items;
    }

    /**
     * Replaces a nested form marker with the optional IPSView output controls.
     *
     * @param array<int,array<string,mixed>> $elements Form elements to search recursively.
     *
     * @throws InvalidArgumentException If the marker caption is empty.
     */
    protected function InsertIPSViewHTMLPageFormItems(
        array &$elements,
        string $markerCaption = self::IPSVIEW_HTML_FORM_MARKER,
        string $description = ''
    ): bool {
        $markerCaption = trim($markerCaption);
        if ($markerCaption === '') {
            throw new InvalidArgumentException('IPSView HTML form marker caption must not be empty.');
        }

        foreach ($elements as $index => &$element) {
            if (
                ($element['type'] ?? null) === 'Label'
                && ($element['caption'] ?? null) === $markerCaption
            ) {
                array_splice($elements, $index, 1, $this->IPSViewHTMLPageFormItems($description));
                unset($element);

                return true;
            }

            if (
                isset($element['items'])
                && is_array($element['items'])
                && $this->InsertIPSViewHTMLPageFormItems($element['items'], $markerCaption, $description)
            ) {
                unset($element);

                return true;
            }
        }
        unset($element);

        return false;
    }

    /** Returns whether the optional IPSView HTML output is enabled. */
    protected function IsIPSViewHTMLPageEnabled(): bool
    {
        return $this->ReadPropertyBoolean(self::IPSVIEW_HTML_ENABLE_PROPERTY);
    }

    /**
     * Creates, preserves or explicitly deletes one optional IPSView WebContent variable.
     *
     * The variable is maintained as a native String variable with the Symcon
     * WebContent presentation in HTML mode. Disabling IPSView only stops updates;
     * an existing variable remains untouched until the user confirms deletion in
     * the configuration form. Native visualization tiles remain untouched.
     *
     * @param string    $ident       Stable variable ident.
     * @param string    $caption     Visible variable caption.
     * @param int       $position    Position below the module instance.
     * @param string    $initialHtml Initial HTML written when the variable is newly created.
     * @param bool|null $padding     Optional WebContent padding setting; null preserves the presentation default.
     *
     * @return bool True when the variable was newly created.
     *
     * @throws InvalidArgumentException If ident, caption or position is invalid.
     * @throws RuntimeException         If the consumer does not provide MaintainVariable() or SetValue().
     */
    protected function MaintainIPSViewHTMLVariable(
        string $ident,
        string $caption,
        int $position,
        string $initialHtml = '',
        ?bool $padding = null
    ): bool {
        $ident = $this->NormalizeIPSViewHTMLVariableIdent($ident);
        $caption = trim($caption);
        if ($caption === '') {
            throw new InvalidArgumentException('IPSView HTML variable caption must not be empty.');
        }
        if ($position < 0) {
            throw new InvalidArgumentException('IPSView HTML variable position must not be negative.');
        }

        $this->RememberIPSViewHTMLVariable($ident, $caption);

        if (!$this->IsIPSViewHTMLPageEnabled()) {
            return false;
        }
        if (!method_exists($this, 'MaintainVariable') || !method_exists($this, 'SetValue')) {
            throw new RuntimeException('IPSViewHTMLPageHelper requires MaintainVariable() and SetValue().');
        }

        $presentation = [
            'PRESENTATION' => VARIABLE_PRESENTATION_WEB_CONTENT,
            'HTML_TYPE'    => 0
        ];
        if ($padding !== null) {
            $presentation['PADDING'] = $padding;
        }

        $created = $this->MaintainVariable(
            $ident,
            $caption,
            VARIABLETYPE_STRING,
            $presentation,
            $position,
            true
        );

        if ($created) {
            $this->SetValue($ident, $initialHtml);
        }

        return $created;
    }

    /**
     * Updates an existing optional IPSView HTML variable when output is enabled.
     *
     * Missing variables and disabled output are ignored. Runtime write errors
     * are reported through SendDebug() and return false, matching the defensive
     * behavior used by existing consumer modules.
     */
    protected function UpdateIPSViewHTMLVariable(string $ident, string $html): bool
    {
        $ident = $this->NormalizeIPSViewHTMLVariableIdent($ident);
        if (!$this->IsIPSViewHTMLPageEnabled()) {
            return false;
        }
        if (!$this->IPSViewHTMLVariableExists($ident)) {
            return false;
        }
        if (!method_exists($this, 'SetValue')) {
            throw new RuntimeException('IPSViewHTMLPageHelper requires SetValue().');
        }

        try {
            $this->SetValue($ident, $html);

            return true;
        } catch (Throwable $exception) {
            if (method_exists($this, 'SendDebug')) {
                $this->SendDebug(__FUNCTION__, $exception->getMessage(), 0);
            }

            return false;
        }
    }

    /**
     * Renders a complete visualization document from index.html, style.css and app.js.
     *
     * Required template placeholders:
     * {{HTML_LANGUAGE}}, {{HTML_CLASSES}}, {{VIEWPORT_CONTENT}},
     * {{ROOT_FONT_SIZE}}, {{VISUALIZATION_THEME}}, {{MODULE_STYLE}},
     * {{IPSVIEW_STYLE}}, {{BOOTSTRAP_JSON}} and {{MODULE_SCRIPT}}.
     *
     * @param bool                $ipsView       True for the standalone IPSView page, false for the native HTML-SDK page.
     * @param array<string,mixed> $configuration Page metadata, bootstrap data and optional custom replacements.
     *
     * @return string Fully rendered HTML document, or an empty string when a required asset cannot be loaded.
     *
     * @throws InvalidArgumentException If the configuration or a page value is invalid.
     * @throws JsonException            If bootstrap data cannot be encoded.
     * @throws RuntimeException         If the consumer does not provide VisualizationAsset() or the template contract is invalid.
     */
    protected function RenderVisualizationHTMLPage(bool $ipsView, array $configuration = []): string
    {
        $this->ValidateIPSViewHTMLConfiguration($configuration);

        $templateAsset = $this->IPSViewHTMLStringOption($configuration, 'templateAsset', 'index.html');
        $styleAsset = $this->IPSViewHTMLStringOption($configuration, 'styleAsset', 'style.css');
        $scriptAsset = $this->IPSViewHTMLStringOption($configuration, 'scriptAsset', 'app.js');

        $template = $this->LoadIPSViewHTMLAsset($templateAsset);
        $moduleStyle = $this->LoadIPSViewHTMLAsset($styleAsset);
        $moduleScript = $this->LoadIPSViewHTMLAsset($scriptAsset);
        if ($template === '' || $moduleStyle === '' || $moduleScript === '') {
            return '';
        }

        $this->ValidateIPSViewHTMLTemplate($template);

        $language = $this->NormalizeIPSViewHTMLLanguage(
            $this->IPSViewHTMLStringOption($configuration, 'language', 'en')
        );
        $classes = $this->NormalizeIPSViewHTMLClasses(
            $configuration['classes'] ?? ($ipsView ? ['ipsview-mode'] : [])
        );
        $viewport = $this->NormalizeIPSViewHTMLViewport(
            $this->IPSViewHTMLStringOption(
                $configuration,
                'viewport',
                $ipsView
                    ? 'width=device-width, initial-scale=1, maximum-scale=1'
                    : 'width=device-width, initial-scale=1'
            )
        );
        $rootFontSize = $this->NormalizeIPSViewHTMLRootFontSize(
            $this->IPSViewHTMLStringOption($configuration, 'rootFontSize', '100%')
        );
        $title = $this->IPSViewHTMLStringOption($configuration, 'title', '');
        $visualizationTheme = $this->IPSViewHTMLStringOption($configuration, 'visualizationTheme', '');
        $ipsViewStyle = $this->IPSViewHTMLStringOption($configuration, 'ipsViewStyle', '');
        $bootstrap = $this->BuildVisualizationHTMLBootstrap($ipsView, $configuration);

        $replacements = [
            '{{HTML_LANGUAGE}}'       => htmlspecialchars($language, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{HTML_CLASSES}}'        => htmlspecialchars($classes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{VIEWPORT_CONTENT}}'    => htmlspecialchars($viewport, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{ROOT_FONT_SIZE}}'      => $rootFontSize,
            '{{DOCUMENT_TITLE}}'      => htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '{{VISUALIZATION_THEME}}' => $ipsView ? '' : $visualizationTheme,
            '{{MODULE_STYLE}}'        => $moduleStyle,
            '{{IPSVIEW_STYLE}}'       => $ipsView ? $ipsViewStyle : '',
            '{{BOOTSTRAP_JSON}}'      => $this->EncodeVisualizationHTMLJSON($bootstrap),
            '{{MODULE_SCRIPT}}'       => $moduleScript
        ];

        foreach ($this->NormalizeIPSViewHTMLReplacements($configuration['replacements'] ?? []) as $placeholder => $value) {
            if (array_key_exists($placeholder, $replacements)) {
                throw new InvalidArgumentException('Core visualization placeholder cannot be replaced: ' . $placeholder);
            }

            $replacements[$placeholder] = $value;
        }

        $html = str_replace(array_keys($replacements), array_values($replacements), $template);
        if (preg_match('/\{\{[A-Z][A-Z0-9_]*\}\}/', $html, $match) === 1) {
            throw new RuntimeException('Unresolved visualization placeholder: ' . $match[0]);
        }

        return $html;
    }

    /**
     * Encodes data for safe embedding into a script element.
     *
     * @param mixed $payload JSON-compatible data.
     *
     * @return string Encoded JSON preserving Unicode, slashes and float fractions.
     *
     * @throws JsonException If the payload cannot be encoded.
     */
    protected function EncodeVisualizationHTMLJSON(mixed $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Returns translated source strings from the concrete module's locale.json.
     *
     * Every source key found in every locale is included. Additional keys can
     * be supplied for runtime-only strings that do not occur in locale.json.
     *
     * @param list<string> $additionalKeys Additional source strings.
     *
     * @return array<string,string> Source strings mapped to the active Symcon translation.
     */
    protected function IPSViewTranslationsFromLocale(array $additionalKeys = []): array
    {
        $keys = $this->NormalizeIPSViewHTMLTranslationKeys($additionalKeys);
        $localePath = $this->ResolveIPSViewHTMLModuleDirectory() . DIRECTORY_SEPARATOR . 'locale.json';
        if (is_file($localePath)) {
            try {
                $locale = json_decode((string) file_get_contents($localePath), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($locale) && isset($locale['translations']) && is_array($locale['translations'])) {
                    foreach ($locale['translations'] as $translations) {
                        if (!is_array($translations)) {
                            continue;
                        }

                        foreach (array_keys($translations) as $source) {
                            if (is_string($source) && $source !== '') {
                                $keys[] = $source;
                            }
                        }
                    }
                }
            } catch (JsonException $exception) {
                $this->SendDebug(__FUNCTION__, 'Invalid locale.json: ' . $exception->getMessage(), 0);
            }
        }

        return $this->TranslateIPSViewHTMLKeys(array_values(array_unique($keys)));
    }

    /**
     * Translates an explicit set of source strings with the active Symcon locale.
     *
     * @param list<string> $keys Source strings.
     *
     * @return array<string,string> Source strings mapped to translated strings.
     */
    protected function IPSViewTranslationsFor(array $keys): array
    {
        return $this->TranslateIPSViewHTMLKeys($this->NormalizeIPSViewHTMLTranslationKeys($keys));
    }

    /**
     * @param array<string,mixed> $configuration
     *
     * @return array{contractVersion:int,mode:string,state:array<mixed>|null,runtime:array<mixed>|null,translations:array<string,string>,options:array<mixed>}
     */
    private function BuildVisualizationHTMLBootstrap(bool $ipsView, array $configuration): array
    {
        $state = $configuration['state'] ?? null;
        if ($state !== null && !is_array($state)) {
            throw new InvalidArgumentException('Visualization state must be an array or null.');
        }

        $runtime = $configuration['runtime'] ?? null;
        if ($runtime !== null && !is_array($runtime)) {
            throw new InvalidArgumentException('Visualization runtime configuration must be an array or null.');
        }

        $translations = $configuration['translations'] ?? [];
        if (!is_array($translations)) {
            throw new InvalidArgumentException('Visualization translations must be an array.');
        }
        foreach ($translations as $source => $translation) {
            if (!is_string($source) || !is_string($translation)) {
                throw new InvalidArgumentException('Visualization translations must map strings to strings.');
            }
        }

        $options = $configuration['options'] ?? [];
        if (!is_array($options)) {
            throw new InvalidArgumentException('Visualization options must be an array.');
        }

        return [
            'contractVersion' => self::IPSVIEW_HTML_CONTRACT_VERSION,
            'mode'            => $ipsView ? 'ipsview' : 'symcon',
            'state'           => $state,
            'runtime'         => $runtime,
            'translations'    => $translations,
            'options'         => $options
        ];
    }

    /** @param array<string,mixed> $configuration */
    private function ValidateIPSViewHTMLConfiguration(array $configuration): void
    {
        foreach (array_keys($configuration) as $key) {
            if (!is_string($key) || !in_array($key, self::IPSVIEW_HTML_CONFIGURATION_KEYS, true)) {
                throw new InvalidArgumentException('Unknown visualization page configuration key: ' . (string) $key);
            }
        }
    }

    private function ValidateIPSViewHTMLTemplate(string $template): void
    {
        foreach (self::IPSVIEW_HTML_REQUIRED_PLACEHOLDERS as $placeholder) {
            if (!str_contains($template, $placeholder)) {
                throw new RuntimeException('Required visualization placeholder is missing: ' . $placeholder);
            }
        }
    }

    private function LoadIPSViewHTMLAsset(string $filename): string
    {
        if (!method_exists($this, 'VisualizationAsset')) {
            throw new RuntimeException('IPSViewHTMLPageHelper requires VisualizationAssetHelper.');
        }

        return $this->VisualizationAsset($filename);
    }

    /** @param array<string,mixed> $configuration */
    private function IPSViewHTMLStringOption(array $configuration, string $key, string $default): string
    {
        $value = $configuration[$key] ?? $default;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Visualization page option must be a string: ' . $key);
        }

        return $value;
    }

    private function NormalizeIPSViewHTMLLanguage(string $language): string
    {
        $language = trim($language);
        if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language) !== 1) {
            throw new InvalidArgumentException('Invalid visualization HTML language: ' . $language);
        }

        return $language;
    }

    /** @param mixed $classes */
    private function NormalizeIPSViewHTMLClasses(mixed $classes): string
    {
        if (is_string($classes)) {
            $classes = preg_split('/\s+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($classes)) {
            throw new InvalidArgumentException('Visualization HTML classes must be a string or an array.');
        }

        $normalized = [];
        foreach ($classes as $class) {
            if (!is_string($class) || preg_match('/^-?[_A-Za-z]+[_A-Za-z0-9-]*$/', $class) !== 1) {
                throw new InvalidArgumentException('Invalid visualization HTML class name.');
            }

            $normalized[] = $class;
        }

        return implode(' ', array_values(array_unique($normalized)));
    }

    private function NormalizeIPSViewHTMLViewport(string $viewport): string
    {
        $viewport = trim($viewport);
        if ($viewport === '' || preg_match('/[\x00-\x1F\x7F]/', $viewport) === 1) {
            throw new InvalidArgumentException('Invalid visualization viewport content.');
        }

        return $viewport;
    }

    private function NormalizeIPSViewHTMLRootFontSize(string $rootFontSize): string
    {
        $rootFontSize = trim($rootFontSize);
        if (preg_match('/^(?:\d+(?:\.\d+)?)(?:%|px|rem|em)$/', $rootFontSize) !== 1) {
            throw new InvalidArgumentException('Invalid visualization root font size: ' . $rootFontSize);
        }

        return $rootFontSize;
    }

    /**
     * @param mixed $replacements
     *
     * @return array<string,string>
     */
    private function NormalizeIPSViewHTMLReplacements(mixed $replacements): array
    {
        if (!is_array($replacements)) {
            throw new InvalidArgumentException('Visualization replacements must be an array.');
        }

        $normalized = [];
        foreach ($replacements as $placeholder => $value) {
            if (!is_string($placeholder)
                || preg_match('/^\{\{[A-Z][A-Z0-9_]*\}\}$/', $placeholder) !== 1
                || !is_string($value)) {
                throw new InvalidArgumentException('Visualization replacements must map valid placeholders to strings.');
            }

            $normalized[$placeholder] = $value;
        }

        return $normalized;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function NormalizeIPSViewHTMLTranslationKeys(array $keys): array
    {
        $normalized = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('Visualization translation keys must be non-empty strings.');
            }

            $normalized[] = $key;
        }

        return $normalized;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string,string>
     */
    private function TranslateIPSViewHTMLKeys(array $keys): array
    {
        sort($keys, SORT_STRING);

        $translations = [];
        foreach ($keys as $key) {
            $translation = $this->Translate($key);
            $translations[$key] = is_string($translation) ? $translation : $key;
        }

        return $translations;
    }

    /**
     * @return array<string,string> Existing retained variables mapped from ident to caption.
     */
    private function IPSViewHTMLRetainedVariables(): array
    {
        $retained = [];
        foreach ($this->ReadIPSViewHTMLVariableRegistry() as $ident => $caption) {
            if ($this->IPSViewHTMLVariableExists($ident)) {
                $retained[$ident] = $caption;
            }
        }

        return $retained;
    }

    /**
     * @param array<string,string> $variables Existing retained variables mapped from ident to caption.
     *
     * @return array<string,mixed> PopupButton form item with an explicit delete confirmation.
     */
    private function IPSViewHTMLDeleteVariablesPopup(array $variables): array
    {
        $popupItems = [
            [
                'type'    => 'Label',
                'caption' => $this->IPSViewHTMLPageText('popup.delete_description')
            ]
        ];
        foreach ($variables as $caption) {
            $popupItems[] = [
                'type'    => 'Label',
                'caption' => '• ' . $caption
            ];
        }

        $message = 'MESSAGE:' . $this->IPSViewHTMLPageText('message.variables_deleted');

        return [
            'type'    => 'PopupButton',
            'caption' => $this->IPSViewHTMLPageText('action.delete_variables'),
            'popup'   => [
                'caption'      => $this->IPSViewHTMLPageText('popup.delete_caption'),
                'closeCaption' => $this->IPSViewHTMLPageText('action.keep_variables'),
                'items'        => $popupItems,
                'buttons'      => [
                    [
                        'caption' => $this->IPSViewHTMLPageText('action.confirm_delete'),
                        'onClick' => [
                            'IPS_RequestAction($id, ' . var_export(self::IPSVIEW_HTML_DELETE_ACTION, true) . ', "");',
                            'return ' . var_export($message, true) . ';'
                        ]
                    ]
                ]
            ]
        ];
    }

    private function RememberIPSViewHTMLVariable(string $ident, string $caption): void
    {
        $registry = $this->ReadIPSViewHTMLVariableRegistry();
        if (($registry[$ident] ?? null) === $caption) {
            return;
        }

        $registry[$ident] = $caption;
        ksort($registry, SORT_STRING);
        $this->WriteAttributeString(
            self::IPSVIEW_HTML_VARIABLE_REGISTRY_ATTRIBUTE,
            json_encode($registry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function DeleteRetainedIPSViewHTMLVariables(): void
    {
        if (!method_exists($this, 'UnregisterVariable')) {
            throw new RuntimeException('IPSViewHTMLPageHelper requires UnregisterVariable() for confirmed deletion.');
        }

        foreach (array_keys($this->ReadIPSViewHTMLVariableRegistry()) as $ident) {
            if ($this->IPSViewHTMLVariableExists($ident)) {
                $this->UnregisterVariable($ident);
            }
        }

        $this->WriteAttributeString(self::IPSVIEW_HTML_VARIABLE_REGISTRY_ATTRIBUTE, '[]');
    }

    /** @return array<string,string> */
    private function ReadIPSViewHTMLVariableRegistry(): array
    {
        return $this->ReadIPSViewHTMLStringMapAttribute(self::IPSVIEW_HTML_VARIABLE_REGISTRY_ATTRIBUTE);
    }

    /** @return array<string,string> */
    private function ReadIPSViewHTMLStringMapAttribute(string $name): array
    {
        try {
            $decoded = json_decode($this->ReadAttributeString($name), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function IPSViewHTMLVariableExists(string $ident): bool
    {
        if (method_exists($this, 'VariableExists')) {
            return (bool) $this->VariableExists($ident);
        }
        if (!method_exists($this, 'GetIDForIdent')) {
            return false;
        }

        try {
            $variableID = @$this->GetIDForIdent($ident);

            return is_int($variableID) && $variableID > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function NormalizeIPSViewHTMLVariableIdent(string $ident): string
    {
        $ident = trim($ident);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $ident) !== 1) {
            throw new InvalidArgumentException('Invalid IPSView HTML variable ident: ' . $ident);
        }

        return $ident;
    }

    private function IPSViewHTMLPageText(string $key): string
    {
        $fallback = self::IPSVIEW_HTML_TRANSLATION_SOURCES[$key] ?? $key;

        return $this->TranslateHelperText('IPSViewHTMLPageHelper', $key, $fallback);
    }

    private function ResolveIPSViewHTMLModuleDirectory(): string
    {
        $reflection = new ReflectionClass($this);
        $moduleFile = $reflection->getFileName();
        if (!is_string($moduleFile) || $moduleFile === '') {
            throw new RuntimeException('The module file path could not be determined.');
        }

        return dirname($moduleFile);
    }
}
