<?php

declare(strict_types=1);

function assertUiRegression(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param list<string> $needles
 */
function assertUiContainsAll(string $source, array $needles, string $message): void
{
    foreach ($needles as $needle) {
        assertUiRegression(
            str_contains($source, $needle),
            $message . ' Missing: ' . $needle
        );
    }
}

/**
 * @return array<string, int>
 */
function uiElementIds(string $source): array
{
    preg_match_all('/\\bid="([^"]+)"/', $source, $matches);

    return array_count_values($matches[1] ?? []);
}

$script = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/app.js');
$indexSource = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/index.html');
$style = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/style.css');

assertUiRegression($script !== '', 'Calendar visualization script must be readable.');
assertUiRegression($indexSource !== '', 'Calendar visualization markup must be readable.');
assertUiRegression($style !== '', 'Calendar visualization stylesheet must be readable.');

$ids = uiElementIds($indexSource);
$duplicateIds = array_keys(array_filter(
    $ids,
    static fn (int $count): bool => $count > 1
));
assertUiRegression(
    $duplicateIds === [],
    'Static visualization markup must not contain duplicate element IDs: ' . implode(', ', $duplicateIds)
);

preg_match_all(
    '/\\b(?:aria-controls|aria-labelledby|aria-describedby)="([^"]+)"/',
    $indexSource,
    $ariaReferences
);
foreach ($ariaReferences[1] ?? [] as $referenceList) {
    foreach (preg_split('/\\s+/', trim($referenceList)) ?: [] as $reference) {
        if ($reference === '') {
            continue;
        }
        assertUiRegression(
            isset($ids[$reference]),
            'Every static ARIA reference must point to an existing element ID: ' . $reference
        );
    }
}

preg_match_all('/<label\\b[^>]*\\bfor="([^"]+)"/', $indexSource, $labelReferences);
foreach ($labelReferences[1] ?? [] as $reference) {
    assertUiRegression(
        isset($ids[$reference]),
        'Every static label target must exist: ' . $reference
    );
}

$views = ['agenda', 'list', 'threeDays', 'week', 'workWeek', 'month'];
assertUiRegression(
    str_contains(
        $script,
        "const calendarViews = new Set(['agenda', 'list', 'threeDays', 'week', 'workWeek', 'month']);"
    ),
    'The JavaScript view registry must keep all supported visualization modes.'
);
foreach ($views as $view) {
    assertUiRegression(
        str_contains($indexSource, 'data-view="' . $view . '"'),
        'The view selector must expose the registered view: ' . $view
    );
}

assertUiContainsAll(
    $script,
    [
        "if (activeView === 'month')",
        'renderMonth();',
        "activeView === 'week' || activeView === 'workWeek'",
        "renderWeek(activeView === 'workWeek');",
        "activeView === 'threeDays'",
        'renderThreeDays();',
        "activeView === 'list'",
        'renderList();',
        'renderAgenda();'
    ],
    'The visualization renderer must keep routing all supported views.'
);

$dialogs = [
    'view-selector-dialog'   => 'view-selector-dialog-title',
    'event-dialog'           => 'dialog-title',
    'event-details-dialog'   => 'details-dialog-title',
    'edit-scope-dialog'      => 'edit-scope-dialog-title',
    'delete-confirm-dialog'  => 'delete-confirm-dialog-title',
    'day-events-dialog'      => 'day-events-dialog-title',
    'calendar-filter-dialog' => 'calendar-filter-dialog-title'
];
foreach ($dialogs as $dialogId => $labelId) {
    assertUiRegression(
        preg_match(
            '/<dialog\\b[^>]*\\bid="' . preg_quote($dialogId, '/') . '"[^>]*\\baria-labelledby="'
                . preg_quote($labelId, '/') . '"[^>]*>/',
            $indexSource
        ) === 1,
        'Every shared dialog must remain a labelled native dialog: ' . $dialogId
    );
}

assertUiContainsAll(
    $indexSource,
    [
        'id="view-selector-button"',
        'aria-haspopup="dialog"',
        'aria-controls="view-selector-dialog"',
        'id="calendar-filter-button"',
        'aria-controls="calendar-filter-dialog"',
        "['view-selector-dialog', 'view-selector-button']",
        "['calendar-filter-dialog', 'calendar-filter-button']",
        "control.setAttribute('aria-expanded', String(dialog.open));",
        "if (!element.hasAttribute('tabindex')) element.tabIndex = 0;",
        "if (!element.hasAttribute('role')) element.setAttribute('role', 'button');",
        "element.setAttribute('aria-keyshortcuts', 'Enter Space');",
        "event.key === 'Enter' || event.key === ' '",
        'const observer = new MutationObserver(scheduleAccessibilitySynchronization);',
        '@media (prefers-reduced-motion: reduce)'
    ],
    'The visualization shell must retain dialog-state synchronization, keyboard access, and reduced-motion support.'
);

assertUiContainsAll(
    $style,
    [
        'html.ipsview-mode {',
        '--cal-view-background: var(--ipsview-role-view-background);',
        '--cal-dialog: var(--ipsview-role-popup-background);',
        '@media (max-width: 560px) {',
        'width: calc(100% - 16px);',
        '@media (max-width: 420px) {',
        'html.ipsview-mode .toolbar .icon-button { width: 40px; height: 40px; }',
        '@media (max-width: 800px) {',
        '.week-grid.three-day-grid {',
        'grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));'
    ],
    'The shared stylesheet must retain IPSView theming and responsive small-screen behavior.'
);

assertUiContainsAll(
    $script,
    [
        'const calendarViewStateStorageKey = Number(calendarOptions.instanceId) > 0',
        'window.localStorage.setItem(calendarViewStateStorageKey, value);',
        'window.localStorage.getItem(calendarViewStateStorageKey);',
        'writeWindowNameViewState(value);',
        'value = readWindowNameViewState();',
        'function shouldDeferCalendarState()',
        'eventEditingActive',
        'eventDialog.open',
        'deferredCalendarState = message.payload;',
        'function applyDeferredCalendarState()'
    ],
    'Client-side view persistence and edit-state deferral must remain intact.'
);

assertUiContainsAll(
    $indexSource,
    [
        "const detailsTarget = document.getElementById('details-description');",
        "const eventDescriptionHtmlNote = document.getElementById('event-description-html-note');",
        'function htmlDescriptionToPlainText(source) {',
        "new DOMParser().parseFromString(source, 'text/html');",
        "parsed.querySelectorAll('script, iframe, frame, frameset, object, embed, form, input, button, select, textarea, base, link, svg, math')",
        "\"default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; base-uri 'none'; form-action 'none'\"",
        "frame.setAttribute('sandbox', 'allow-same-origin allow-popups allow-popups-to-escape-sandbox');",
        "frame.setAttribute('referrerpolicy', 'no-referrer');",
        "if (descriptionUnchanged && ident === 'UpdateEvent' && value?.event?.changes) {"
    ],
    'HTML event descriptions must retain the isolated, sanitized and edit-safe rendering contract.'
);

fwrite(STDOUT, "Visualization UI regression contract passed.\n");
