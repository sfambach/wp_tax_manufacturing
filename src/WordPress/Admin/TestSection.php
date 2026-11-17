<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\WordPress\ManufacturingService;

/**
 * The step «Test» of an order: its completed boards as the stock holds them, ticked and judged through one bar — «Test
 * bestanden», «Test nicht bestanden …» with a note, «Verschrotten …» with a reason (D-1419, D-1403). The stock writes the test
 * report and the scrap booking (D-1420); this section only asks.
 *
 * ```mermaid
 * flowchart LR
 *   U["ungetestet"] -->|"bestanden"| P["funktioniert"]
 *   U -->|"nicht bestanden"| F["defekt · Reparatur"] -->|"neu testen"| P
 *   F -->|"Verschrotten"| S["verschrottet"]
 * ```
 *
 * @see /mnt/project-files/taxmod-fertigung/test-schritt-konzept.md
 */
final class TestSection
{
    public const PASSED = 'taxmod_manufacturing_test_passed';
    public const FAILED = 'taxmod_manufacturing_test_failed';
    public const SCRAP  = 'taxmod_manufacturing_test_scrap';

    public const ACTIONS = [self::PASSED, self::FAILED, self::SCRAP];

    public function __construct(
        private readonly ManufacturingService $service,
        private readonly string $nonce,
        private readonly string $tab,
    ) {
    }

    /** @param array<int, string> $labels */
    public function render(BuildOrder $order, array $labels): string
    {
        if (! $this->service->canTest()) {
            return '<div class="notice notice-warning inline"><p>' . esc_html__('Das Lager bietet die Test-Schnittstelle noch nicht an (Lager ab 0.64.110 mit «taxmod_stock_test_lots»). Die abgeschlossenen Platinen liegen ungeprüft auf «Test»; das Ergebnis lässt sich hier eintragen, sobald das Lager aktualisiert ist.', 'taxmod-manufacturing') . '</p></div>';
        }

        try {
            $lots = $this->service->testLots($order->id);
        } catch (ManufacturingError $error) {
            return '<div class="notice notice-error inline"><p>' . esc_html($error->detail) . '</p></div>';
        }

        if ($lots === []) {
            return '<p class="description">' . esc_html__('Das Lager kennt noch keine Platine dieses Auftrags.', 'taxmod-manufacturing') . '</p>';
        }

        $names = $labels + $this->service->labelsOf(array_values(array_unique([...array_column($lots, 'thing'), ...array_column($lots, 'place')])));
        $form  = 'taxmod-mf-test-' . $order->id;
        $grid  = [];

        foreach ($lots as $lot => $row) {
            $closed = $row['state'] === 'scrapped';
            $tick   = $closed ? '' : '<input type="checkbox" name="lots[]" form="' . esc_attr($form) . '" value="' . esc_attr((string) $lot) . '" aria-label="' . esc_attr__('Auswählen', 'taxmod-manufacturing') . '">';
            $grid[] = [
                $tick,
                $this->lot($lot, $row['thing']),
                esc_html($names[$row['thing']] ?? '#' . $row['thing']),
                esc_html($row['place'] > 0 ? ($names[$row['place']] ?? '#' . $row['place']) : '—'),
                $this->state($row['state']),
                esc_html($row['tested_on']),
                esc_html($row['note']),
            ];
        }

        $note = static fn (string $name, string $label, bool $required): string => '<p><label>' . esc_html($label) . '<br><input type="text" class="regular-text" name="' . esc_attr($name) . '" form="' . esc_attr($form) . '"' . ($required ? ' required' : '') . '></label></p>';
        $bar  = ListPage::bulkBar($form, $this->nonce, [self::PASSED => __('Test bestanden', 'taxmod-manufacturing')], '', '', false, [
            $form . '-failed' => [__('Test nicht bestanden …', 'taxmod-manufacturing'), ListPage::dialogAction($form . '-failed', __('Test nicht bestanden …', 'taxmod-manufacturing'), __('Test nicht bestanden', 'taxmod-manufacturing'),
                $note('note', __('Was ist aufgefallen?', 'taxmod-manufacturing'), true) . '<p class="description">' . esc_html__('Die Platine wartet auf Reparatur; danach einfach neu testen.', 'taxmod-manufacturing') . '</p>', 'action', self::FAILED, __('Eintragen', 'taxmod-manufacturing'), $form)],
            $form . '-scrap'  => [__('Verschrotten …', 'taxmod-manufacturing'), ListPage::dialogAction($form . '-scrap', __('Verschrotten …', 'taxmod-manufacturing'), __('Platinen verschrotten?', 'taxmod-manufacturing'),
                $note('reason', __('Grund', 'taxmod-manufacturing'), true) . '<p class="description">' . esc_html__('Die Platinen gehen ganz als «Defekt/Ausschuss» aus dem Lager, mit diesem Auftrag als Bezug. Ein Vorgang, zurücknehmen über die Änderungsnummer.', 'taxmod-manufacturing') . '</p>', 'action', self::SCRAP, __('Verschrotten', 'taxmod-manufacturing'), $form)],
        ]) . $this->hidden(['order' => (string) $order->id, 'tab' => $this->tab], $form);

        // The lines of a form, not a list: no filter, grouping or actions column (his report 17.11. «button chaos»).
        return $bar . ListPage::grid([
            [''], [__('Posten', 'taxmod-manufacturing')], [__('Platine', 'taxmod-manufacturing')], [__('Lagerort', 'taxmod-manufacturing')],
            [__('Zustand', 'taxmod-manufacturing')], [__('Getestet am', 'taxmod-manufacturing')], [__('Befund', 'taxmod-manufacturing')],
        ], $grid);
    }

    private function state(string $state): string
    {
        [$word, $class] = match ($state) {
            'passed'   => [__('bestanden', 'taxmod-manufacturing'), 'ok'],
            'failed'   => [__('nicht bestanden · Reparatur', 'taxmod-manufacturing'), 'warn'],
            'scrapped' => [__('verschrottet', 'taxmod-manufacturing'), 'info'],
            default    => [__('ungetestet', 'taxmod-manufacturing'), 'info'],
        };

        return '<span class="taxmod-mf-status taxmod-mf-status-' . esc_attr($class) . '">' . esc_html($word) . '</span>';
    }

    /** The lot's own line in its material's stock page (his rule 15.11.: stock objects link to the stock module). */
    private function lot(int $lot, int $thing): string
    {
        $page = \TaxmodStock\WordPress\Admin\StockPage::class;

        if (! class_exists($page) || ! method_exists($page, 'lotUrl')) {
            return esc_html('#' . $lot);
        }

        return '<a href="' . esc_url($page::lotUrl($thing, $lot)) . '">' . esc_html('#' . $lot) . '</a>';
    }

    /** @param array<string, string> $fields */
    private function hidden(array $fields, string $form): string
    {
        $html = '';

        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" form="' . esc_attr($form) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }

        return $html;
    }
}
