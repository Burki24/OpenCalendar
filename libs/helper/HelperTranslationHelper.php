<?php

declare(strict_types=1);

namespace Burki24\SymconModuleHelper;

use JsonException;

// Keep the declaration conditional: cached includes may bind unconditional types before an early return.
if (!trait_exists(HelperTranslationHelper::class, false)) {
    /**
     * Loads helper-owned translations from vendored JSON catalogs.
     *
     * Visible texts produced by a shared helper are translated by the helper
     * itself. Consumer modules therefore do not need matching locale.json
     * entries for helper captions or hints.
     *
     * @version 1.0.2
     */
    trait HelperTranslationHelper
    {
        /** @var array<string,array<string,mixed>> */
        private static array $helperTranslationCatalogCache = [];

        /**
         * Translates one helper-owned text.
         *
         * @param string      $catalog  Catalog name without path or extension.
         * @param string      $key      Stable translation key.
         * @param string      $fallback English fallback text.
         * @param string|null $language Optional language override, primarily for tests.
         */
        protected function TranslateHelperText(
            string $catalog,
            string $key,
            string $fallback,
            ?string $language = null
        ): string {
            return $this->TranslateHelperTextFromDirectory(__DIR__ . '/translations', $catalog, $key, $fallback, $language);
        }

        /** Resolve resources next to the calling helper, independent of which library declared this trait first. */
        protected function TranslateHelperTextFromDirectory(
            string $directory,
            string $catalog,
            string $key,
            string $fallback,
            ?string $language = null
        ): string {
            $catalog = trim($catalog);
            $key = trim($key);
            if ($catalog === '' || $key === '' || preg_match('/^[A-Za-z0-9_.-]+$/', $catalog) !== 1) {
                return $fallback;
            }

            $language = $this->NormalizeHelperTranslationLanguage(
                $language ?? $this->ResolveHelperTranslationLanguage()
            );
            $translations = $this->LoadHelperTranslationCatalog($directory, $catalog);
            $entry = $translations['translations'][$key] ?? null;
            if (!is_array($entry)) {
                return $fallback;
            }

            $translated = $entry[$language] ?? $entry['en'] ?? null;

            return is_string($translated) && trim($translated) !== '' ? $translated : $fallback;
        }

        /** Returns the active helper translation language. */
        protected function ResolveHelperTranslationLanguage(): string
        {
            if (method_exists($this, 'HelperTranslationLanguageOverride')) {
                $override = $this->HelperTranslationLanguageOverride();
                if (is_string($override) && trim($override) !== '') {
                    return $override;
                }
            }

            if (function_exists('IPS_GetSystemLanguage')) {
                $language = IPS_GetSystemLanguage();
                if (is_string($language) && trim($language) !== '') {
                    return $language;
                }
            }

            return 'en';
        }

        /** Normalizes values such as de_DE.UTF-8 to de. */
        protected function NormalizeHelperTranslationLanguage(string $language): string
        {
            $language = strtolower(trim($language));
            if ($language === '') {
                return 'en';
            }

            $language = preg_split('/[_\-.@]/', $language, 2)[0] ?? 'en';

            return preg_match('/^[a-z]{2,3}$/', $language) === 1 ? $language : 'en';
        }

        /** @return array<string,mixed> */
        private function LoadHelperTranslationCatalog(string $directory, string $catalog): array
        {
            $path = $directory . '/' . $catalog . '.json';
            if (array_key_exists($path, self::$helperTranslationCatalogCache)) {
                return self::$helperTranslationCatalogCache[$path];
            }

            if (!is_file($path)) {
                return self::$helperTranslationCatalogCache[$path] = [];
            }

            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return self::$helperTranslationCatalogCache[$path] = [];
            }

            return self::$helperTranslationCatalogCache[$path] = is_array($decoded) ? $decoded : [];
        }
    }
}
