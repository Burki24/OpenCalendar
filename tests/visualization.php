<?php

declare(strict_types=1);

use Burki24\SymconModuleHelper\IPSViewHTMLPageHelper;

require_once __DIR__ . '/../libs/helper/IPSViewHTMLPageHelper.php';

final class CalendarVisualizationRenderer
{
    use IPSViewHTMLPageHelper;

    public function render(bool $ipsView): string
    {
        return $this->RenderVisualizationHTMLPage($ipsView, [
            'language'           => 'de',
            'classes'            => $ipsView ? ['ipsview-mode'] : [],
            'rootFontSize'       => $ipsView ? '18px' : '100%',
            'title'              => 'Kalender',
            'visualizationTheme' => ':root { --symc-accent: #123456; }',
            'ipsViewStyle'       => ':root { --ipsview-accent: #654321; }',
            'state'              => [
                'events'    => [],
                'calendars' => [],
                'settings'  => ['defaultView' => 'agenda']
            ],
            'runtime'      => null,
            'translations' => ['Today' => 'Heute'],
            'options'      => [
                'agendaColorBarWidth'  => 7,
                'compactColorBarWidth' => 7
            ]
        ]);
    }

    public function VisualizationAsset(string $name): string
    {
        $path = __DIR__ . '/../Kalender Ansicht/visualization/' . $name;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public function Translate(string $text): string
    {
        return $text;
    }

    public function SendDebug(string $message, mixed $data, int $format): void
    {
    }
}

function assertVisualization(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$style = (string) file_get_contents(__DIR__ . '/../Kalender Ansicht/visualization/style.css');
assertVisualization(
    str_contains($style, '--cal-view-background: var(--ipsview-role-view-background);')
        && str_contains($style, '--cal-page-background: var(--ipsview-role-page-background);')
        && str_contains($style, '--cal-text: var(--ipsview-role-text-primary);')
        && str_contains($style, '--cal-text-active: var(--ipsview-role-text-active);')
        && str_contains($style, '--cal-text-inactive: var(--ipsview-role-text-inactive);')
        && str_contains($style, '--cal-label-text: var(--ipsview-role-text-label);')
        && str_contains($style, '--cal-muted: var(--ipsview-role-text-secondary);')
        && str_contains($style, '--cal-faint: var(--ipsview-role-text-faint);')
        && str_contains($style, '--cal-icon: var(--ipsview-role-icon);'),
    'The calendar stylesheet must map every text, icon and page role to the canonical IPSView contract.'
);
assertVisualization(
    str_contains($style, 'background: var(--cal-view-background);')
        && str_contains($style, 'background: var(--cal-page-background);')
        && str_contains($style, 'color: var(--cal-text-active, var(--cal-text));')
        && str_contains($style, 'color: var(--cal-text-inactive);')
        && str_contains($style, 'color: var(--cal-label-text);')
        && str_contains($style, 'color: var(--cal-icon);'),
    'Calendar components must consume the canonical roles according to their semantic purpose.'
);

$renderer = new CalendarVisualizationRenderer();
$native = $renderer->render(false);
$ipsView = $renderer->render(true);

foreach ([$native, $ipsView] as $html) {
    assertVisualization(str_starts_with($html, '<!DOCTYPE html>'), 'The rendered page must be a complete HTML document.');
    assertVisualization(!preg_match('/\{\{[A-Z][A-Z0-9_]*\}\}/', $html), 'No visualization placeholder may remain unresolved.');
    assertVisualization(str_contains($html, 'window.SYMC_VISUALIZATION = '), 'The shared bootstrap object must be embedded.');
    assertVisualization(str_contains($html, 'contractVersion'), 'The bootstrap contract version must be embedded.');
    assertVisualization(str_contains($html, 'calendarVisualization.state'), 'The calendar script must consume the shared state contract.');
    assertVisualization(str_contains($html, '--agenda-color-bar-width'), 'Calendar-specific options must remain available through the shared bootstrap.');
}

assertVisualization(str_contains($native, '--symc-accent: #123456'), 'The native page must include the Symcon visualization theme.');
assertVisualization(!str_contains($native, '--ipsview-accent: #654321'), 'The native page must not include the IPSView style.');
assertVisualization(!str_contains($native, 'class="ipsview-mode"'), 'The native page must not use the IPSView class.');
assertVisualization(str_contains($ipsView, '--ipsview-accent: #654321'), 'The IPSView page must include the selected IPSView style.');
assertVisualization(!str_contains($ipsView, '--symc-accent: #123456'), 'The IPSView page must not include the native visualization theme.');
assertVisualization(str_contains($ipsView, 'class="ipsview-mode"'), 'The IPSView page must expose its shared mode class.');
assertVisualization(str_contains($ipsView, '"mode":"ipsview"'), 'The IPSView bootstrap mode must be explicit.');
assertVisualization(str_contains($native, '"mode":"symcon"'), 'The native bootstrap mode must be explicit.');

fwrite(STDOUT, "Calendar visualization contract checks passed.\n");
