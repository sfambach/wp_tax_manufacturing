<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\WordPress\ManufacturingService;

/**
 * The step «Test» of an order: its completed boards as the stock holds them, ticked and judged through one bar — «Test
 * bestanden», «Nacharbeit …» with a note, «Verschrotten …» with a reason (D-1419, D-1403). The stock writes the test
 * report and the scrap booking (D-1420); this section only asks.
 *
 * ```mermaid
 * flowchart LR
 *   U["ungetestet"] -->|"bestanden"| P["funktioniert"]
 *   U -->|"nicht bestanden"| F["Nacharbeit"] -->|"neu testen"| P
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

        $costs = $this->service->costOf(array_keys($lots));
        $parts = array_merge([], ...array_map(static fn (array $cost): array => [...array_column((array) ($cost['parts'] ?? []), 'thing'), ...array_column((array) ($cost['scrap'] ?? []), 'thing')], array_values($costs)));
        $names = $labels + $this->service->labelsOf(array_values(array_unique([...array_column($lots, 'thing'), ...array_column($lots, 'place'), ...array_map('intval', $parts)])));
        $priced = $this->service->canPrice() || $costs !== [];
        $form  = 'taxmod-mf-test-' . $order->id;
        $grid  = [];
        // The bar counts and sends only boxes named «selected[]» (ListPage::bulkBar); any other name reads as nothing ticked
        // (his report 17.11. 21:56 «test bestanden bulk macht nix … ein item selektiert»).

        foreach ($lots as $lot => $row) {
            $closed = $row['state'] === 'scrapped';
            $tick   = $closed ? '' : '<input type="checkbox" name="selected[]" form="' . esc_attr($form) . '" value="' . esc_attr((string) $lot) . '" aria-label="' . esc_attr__('Auswählen', 'taxmod-manufacturing') . '">';
            $grid[] = [
                $tick,
                $this->lot($lot, $row['thing']),
                esc_html($names[$row['thing']] ?? '#' . $row['thing']),
                esc_html($row['place'] > 0 ? ($names[$row['place']] ?? '#' . $row['place']) : '—'),
                $this->state($row['state']),
                esc_html($row['tested_on']),
                esc_html($row['note']),
                ...($priced ? [isset($costs[$lot]) ? $this->cost($costs[$lot], $names) : '—'] : []),
                $closed ? '' : $this->judge($order, $lot, $row['state'], $row['note']),
            ];
        }

        // Not «required»: the boxes belong to the bar's form even while their dialog is shut, and an empty required box there
        // blocks every action of the bar without a word (his report 17.11. 21:56). The handler asks for them instead.
        $note = static fn (string $name, string $label, bool $required): string => '<p><label>' . esc_html($label) . '<br><input type="text" class="regular-text" name="' . esc_attr($name) . '" form="' . esc_attr($form) . '"></label></p>';
        $bar  = ListPage::bulkBar($form, $this->nonce, [self::PASSED => __('Test bestanden', 'taxmod-manufacturing')], '', '', false, [
            $form . '-failed' => [__('Nacharbeit …', 'taxmod-manufacturing'), ListPage::dialogAction($form . '-failed', __('Nacharbeit …', 'taxmod-manufacturing'), __('Nacharbeit', 'taxmod-manufacturing'),
                $note('note', __('Was ist aufgefallen?', 'taxmod-manufacturing'), true) . '<p class="description">' . esc_html__('Die Platinen warten auf Nacharbeit; danach neu testen.', 'taxmod-manufacturing') . '</p>', 'action', self::FAILED, __('Eintragen', 'taxmod-manufacturing'), $form)],
            $form . '-scrap'  => [__('Verschrotten …', 'taxmod-manufacturing'), ListPage::dialogAction($form . '-scrap', __('Verschrotten …', 'taxmod-manufacturing'), __('Platinen verschrotten?', 'taxmod-manufacturing'),
                $note('reason', __('Grund', 'taxmod-manufacturing'), true) . '<p class="description">' . esc_html__('Die Platinen gehen ganz als «Defekt/Ausschuss» aus dem Lager, mit diesem Auftrag als Bezug. Ein Vorgang, zurücknehmen über die Änderungsnummer.', 'taxmod-manufacturing') . '</p>', 'action', self::SCRAP, __('Verschrotten', 'taxmod-manufacturing'), $form)],
        ]) . $this->hidden(['order' => (string) $order->id, 'tab' => $this->tab], $form);

        // The lines of a form, not a list: no filter, grouping or actions column (his report 17.11. «button chaos»).
        return $bar . ListPage::grid([
            [__('Alle wählen', 'taxmod-manufacturing')], [__('Posten', 'taxmod-manufacturing')], [__('Platine', 'taxmod-manufacturing')], [__('Lagerort', 'taxmod-manufacturing')],
            [__('Zustand', 'taxmod-manufacturing')], [__('Getestet am', 'taxmod-manufacturing')], [__('Befund', 'taxmod-manufacturing')],
            ...($priced ? [[__('Preis je Platine', 'taxmod-manufacturing')]] : []),
            [''],
        ], $grid, '', 'taxmod-mf-ticks');
    }

    /**
     * D-1425, his word 17.11. «der presi soll transparent sein»: the frozen price of a board, opened to its lines.
     *
     * @param array<string, mixed> $cost
     * @param array<int, string> $names
     */
    private function cost(array $cost, array $names): string
    {
        $money  = static fn (float|int|string|null $n): string => ListPage::number(number_format((float) $n, 2, '.', '')) . ' €';
        $number = static fn (float|int|string|null $n): string => ListPage::number((float) $n);
        $lines  = '';

        foreach ((array) ($cost['parts'] ?? []) as $part) {
            $name   = $names[(int) ($part['thing'] ?? 0)] ?? '#' . (int) ($part['thing'] ?? 0);
            $price  = $part['unit_price'] === null ? __('ohne Preis', 'taxmod-manufacturing') : $money($part['unit_price']) . ' = ' . $money((float) $part['quantity'] * (float) $part['unit_price']);
            $marker = ($part['source'] ?? '') === 'last_purchase' ? ' ' . __('(letzter Kaufpreis)', 'taxmod-manufacturing') : '';
            $lines .= '<li>' . esc_html($number($part['quantity']) . ' × ' . $name . ': ' . $price . $marker) . '</li>';
        }

        $setup = (array) ($cost['setup'] ?? []);
        $work  = (array) ($cost['work'] ?? []);
        /* translators: 1: hours, 2: wage, 3: boards, 4: amount */
        $lines .= '<li>' . esc_html(sprintf(__('Bereitstellung: %1$s h × %2$s ÷ %3$d Platinen = %4$s', 'taxmod-manufacturing'), $number($setup['hours'] ?? 0), $money($setup['wage'] ?? 0), (int) ($setup['divisor'] ?? 1), $money($setup['amount'] ?? 0))) . '</li>';
        /* translators: 1: minutes, 2: wage, 3: amount */
        $lines .= '<li>' . esc_html(sprintf(__('Arbeit: %1$s min × %2$s je Stunde = %3$s', 'taxmod-manufacturing'), $number($work['minutes'] ?? 0), $money($work['wage'] ?? 0), $money($work['amount'] ?? 0))) . '</li>';

        foreach ((array) ($cost['scrap'] ?? []) as $scrap) {
            $lines .= '<li>' . esc_html(sprintf(__('Ausschuss (nicht eingerechnet): %1$s × %2$s', 'taxmod-manufacturing'), $number($scrap['quantity'] ?? 0), $names[(int) ($scrap['thing'] ?? 0)] ?? '#' . (int) ($scrap['thing'] ?? 0))) . '</li>';
        }

        $total = $money($cost['total'] ?? 0) . (($cost['complete'] ?? true) ? '' : ' ' . __('(unvollständig)', 'taxmod-manufacturing'));

        return '<details><summary>' . esc_html($total) . '</summary><ul>' . $lines . '</ul></details>';
    }

    /**
     * His words 17.11. 22:04 «bei einzelnen Testpositionen sagen getestet, Nacharbeit» and 18.11. «ein Button bestanden oder
     * Nacharbeit» — one board judged on its own: «Bestanden» at once, «Nacharbeit …» with its Befund, in a form beside the bar.
     */
    private function judge(BuildOrder $order, int $lot, string $state, string $note): string
    {
        $form   = 'taxmod-mf-judge-' . $lot;
        $passed = $state === 'passed' ? '' : '<button type="submit" class="button" name="action" value="' . esc_attr(self::PASSED) . '" form="' . esc_attr($form) . '">' . esc_html__('Bestanden', 'taxmod-manufacturing') . '</button> ';
        $body   = '<p><label>' . esc_html__('Befund', 'taxmod-manufacturing') . '<br><input type="text" class="regular-text" name="note" form="' . esc_attr($form) . '" value="' . esc_attr($note) . '"></label></p>'
            . '<p class="description">' . esc_html__('Die Platine wartet auf Nacharbeit; danach neu testen.', 'taxmod-manufacturing') . '</p>';

        // Side by side, one size (G14–G16): the two answers of one board read as one choice.
        return '<form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-mf-judge">' . wp_nonce_field($this->nonce, '_wpnonce', true, false)
            . $this->hidden(['order' => (string) $order->id, 'tab' => $this->tab, 'selected[]' => (string) $lot], $form)
            . $passed
            . ListPage::dialogAction($form . '-dialog', __('Nacharbeit …', 'taxmod-manufacturing'), sprintf(__('Platine #%d: Nacharbeit', 'taxmod-manufacturing'), $lot), $body, 'action', self::FAILED, __('Eintragen', 'taxmod-manufacturing'), $form)
            . '</form>';
    }

    private function state(string $state): string
    {
        [$word, $class] = match ($state) {
            'passed'   => [__('bestanden', 'taxmod-manufacturing'), 'ok'],
            'failed'   => [__('Nacharbeit', 'taxmod-manufacturing'), 'warn'],
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
