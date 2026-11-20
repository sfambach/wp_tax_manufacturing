<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Positions;
use TaxmodManufacturing\WordPress\ManufacturingService;

/**
 * The boards of an order with their statuses (D-1433): a bar with the count per status, and each board with what it can do
 * next — «Bestanden» finishes it and moves it to «Wareneingang Produktion», «Nacharbeit …» with a Befund, back «Zurück in
 * Test …», «Verschrotten …» with a reason — through one bar or per row (D-1419, D-1403). The stock holds condition and place
 * and writes every booking (D-1342, D-1420); this section only asks.
 *
 * ```mermaid
 * flowchart LR
 *   T["Test"] -->|"Bestanden"| D["Fertiggestellt · WE-PROD"]
 *   T -->|"Nacharbeit …"| R["Nacharbeit"] -->|"Zurück in Test …"| T
 *   R -->|"Verschrotten …"| S["Verschrottet"]
 *   T -->|"Verschrotten …"| S
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1433
 */
final class TestSection
{
    public const PASSED = 'taxmod_manufacturing_test_passed';
    public const FAILED = 'taxmod_manufacturing_test_failed';
    public const SCRAP  = 'taxmod_manufacturing_test_scrap';
    public const RETEST = 'taxmod_manufacturing_test_retest';

    public const ACTIONS = [self::PASSED, self::FAILED, self::SCRAP, self::RETEST];

    public function __construct(
        private readonly ManufacturingService $service,
        private readonly string $nonce,
        private readonly string $tab,
    ) {
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, array{thing: int, place: int, quantity: float, state: string, tested_on: string, note: string}> $lots the
     *        order's boards as the stock holds them, read once by the page
     * @param array<string, int> $counts status → boards ({@see Positions::counts()})
     */
    public function render(BuildOrder $order, array $labels, array $lots, array $counts): string
    {
        if (! $this->service->canTest()) {
            return '<div class="notice notice-warning inline"><p>' . esc_html__('Das Lager bietet die Test-Schnittstelle noch nicht an (Lager ab 0.64.110 mit «taxmod_stock_test_lots»). Die abgeschlossenen Stücke liegen ungeprüft auf «Test»; das Ergebnis lässt sich hier eintragen, sobald das Lager aktualisiert ist.', 'taxmod-manufacturing') . '</p></div>';
        }

        $bar = $this->statusBar($order, $counts);

        if ($lots === []) {
            return $bar . '<p class="description">' . esc_html__('Das Lager kennt noch kein Stück dieses Auftrags.', 'taxmod-manufacturing') . '</p>';
        }

        $costs  = $this->service->costOf(array_keys($lots));
        $parts  = array_merge([], ...array_map(static fn (array $cost): array => [...array_column((array) ($cost['parts'] ?? []), 'thing'), ...array_column((array) ($cost['scrap'] ?? []), 'thing')], array_values($costs)));
        $names  = $labels + $this->service->labelsOf(array_values(array_unique([...array_column($lots, 'thing'), ...array_column($lots, 'place'), ...array_map('intval', $parts)])));
        $priced = $this->service->canPrice() || $costs !== [];
        $form   = 'taxmod-mf-test-' . $order->id;
        $grid   = [];
        // The bar counts and sends only boxes named «selected[]» (ListPage::bulkBar); any other name reads as nothing ticked
        // (his report 17.11. 21:56 «test bestanden bulk macht nix … ein item selektiert»).

        foreach ($lots as $lot => $row) {
            $status = Positions::ofLot($row['state']);
            $tick   = in_array($status, Positions::OPEN, true) ? '<input type="checkbox" name="selected[]" form="' . esc_attr($form) . '" value="' . esc_attr((string) $lot) . '" aria-label="' . esc_attr__('Auswählen', 'taxmod-manufacturing') . '">' : '';
            $grid[] = [
                $tick,
                $this->lot($lot, $row['thing']),
                esc_html($names[$row['thing']] ?? '#' . $row['thing']),
                esc_html($row['place'] > 0 ? ($names[$row['place']] ?? '#' . $row['place']) : '—'),
                $this->state($status),
                esc_html($row['tested_on']),
                esc_html($row['note']),
                ...($priced ? [isset($costs[$lot]) ? $this->cost($costs[$lot], $names) : '—'] : []),
                $this->rowActions($order, $lot, $status, $row['note'], $names[$row['thing']] ?? '#' . $row['thing']),
            ];
        }

        // Not «required»: the boxes belong to the bar's form even while their dialog is shut, and an empty required box there
        // blocks every action of the bar without a word (his report 17.11. 21:56). The handler asks for them instead.
        $note    = static fn (string $name, string $label): string => '<p><label>' . esc_html($label) . '<br><input type="text" class="regular-text" name="' . esc_attr($name) . '" form="' . esc_attr($form) . '"></label></p>';
        $dialogs = [
            $form . '-failed' => [__('Nacharbeit …', 'taxmod-manufacturing'), ListPage::dialogAction($form . '-failed', __('Nacharbeit …', 'taxmod-manufacturing'), __('Nacharbeit: ausgewählte Stücke', 'taxmod-manufacturing'),
                $note('note', __('Was ist aufgefallen?', 'taxmod-manufacturing')) . '<p class="description">' . esc_html__('Die Stücke warten auf Nacharbeit; danach zurück in den Test.', 'taxmod-manufacturing') . '</p>', 'action', self::FAILED, __('Eintragen', 'taxmod-manufacturing'), $form)],
        ];

        $dialogs[$form . '-retest'] = [__('Zurück in Test …', 'taxmod-manufacturing'), ListPage::dialogAction($form . '-retest', __('Zurück in Test …', 'taxmod-manufacturing'), __('Zurück in Test: ausgewählte Stücke', 'taxmod-manufacturing'),
                $note('retest_note', __('Was wurde nachgearbeitet? (optional)', 'taxmod-manufacturing')) . '<p class="description">' . esc_html__('Die Stücke kommen ungetestet zurück in den Test.', 'taxmod-manufacturing') . '</p>', 'action', self::RETEST, __('Zurück in Test', 'taxmod-manufacturing'), $form)];
        $dialogs[$form . '-scrap'] = [__('Verschrotten …', 'taxmod-manufacturing'), ListPage::dialogAction($form . '-scrap', __('Verschrotten …', 'taxmod-manufacturing'), __('Verschrotten: ausgewählte Stücke', 'taxmod-manufacturing'),
            $note('reason', __('Grund', 'taxmod-manufacturing')) . '<p class="description">' . esc_html__('Die Stücke gehen ganz als «Defekt/Ausschuss» aus dem Lager, mit diesem Auftrag als Bezug. Ein Vorgang, zurücknehmen über die Änderungsnummer.', 'taxmod-manufacturing') . '</p>', 'action', self::SCRAP, __('Verschrotten', 'taxmod-manufacturing'), $form)];

        $actions = ListPage::bulkBar($form, $this->nonce, [self::PASSED => __('Bestanden', 'taxmod-manufacturing')], '', '', false, $dialogs)
            . $this->hidden(['order' => (string) $order->id, 'tab' => $this->tab], $form);

        // The lines of a form, not a list: no filter, grouping or actions column (his report 17.11. «button chaos»).
        return $bar . $actions . ListPage::grid([
            [__('Alle wählen', 'taxmod-manufacturing')], [__('Posten', 'taxmod-manufacturing')], [__('Bauplan', 'taxmod-manufacturing')], [__('Lagerort', 'taxmod-manufacturing')],
            [__('Status', 'taxmod-manufacturing')], [__('Getestet am', 'taxmod-manufacturing')], [__('Befund', 'taxmod-manufacturing')],
            ...($priced ? [[__('Preis je Stück', 'taxmod-manufacturing')]] : []),
            [''],
        ], $grid, '', 'taxmod-mf-ticks');
    }

    /**
     * D-1433 A, his word 18.11. «zweiter Statusbalken … wie viele Positionen»: the boards per status, display only. Test and
     * Nacharbeit stand side by side — they hand boards to each other (his word 07:55).
     *
     * @param array<string, int> $counts
     */
    private function statusBar(BuildOrder $order, array $counts): string
    {
        $step = static fn (string $status, string $label): array => [$status, $label, $counts[$status] ?? 0];
        $ends = [$step(Positions::DONE, __('Fertig', 'taxmod-manufacturing')), $step(Positions::SCRAPPED, __('Verschrottet', 'taxmod-manufacturing'))];
        $bar  = $order->kit
            ? [$step(Positions::BUILDING, __('In Bau', 'taxmod-manufacturing')), $ends]
            : [$step(Positions::BUILDING, __('In Bau', 'taxmod-manufacturing')), [$step(Positions::TEST, __('In Test', 'taxmod-manufacturing')), $step(Positions::REWORK, __('Nacharbeit', 'taxmod-manufacturing'))], $ends];

        return '<div class="taxmod-mf-positions">' . ListPage::steps($bar, '', null) . '</div>';
    }

    /**
     * His words 17.11. 22:04 «bei einzelnen Testpositionen sagen getestet, Nacharbeit», 18.11. «ein Button bestanden oder
     * Nacharbeit» and D-1433: what a board can do next from its status, in a form beside the bar — and its label, printed only
     * when pressed (D-1433 E).
     */
    private function rowActions(BuildOrder $order, int $lot, string $status, string $note, string $board): string
    {
        $form   = 'taxmod-mf-judge-' . $lot;
        $button = static fn (string $action, string $label): string => ListPage::button($label, '', false, 'action', $action, $form);
        $text   = static fn (string $name, string $label, string $value, string $hint): string => '<p><label>' . esc_html($label) . '<br><input type="text" class="regular-text" name="' . esc_attr($name) . '" form="' . esc_attr($form) . '" value="' . esc_attr($value) . '"></label></p>'
            . '<p class="description">' . esc_html($hint) . '</p>';
        $inner  = match ($status) {
            Positions::TEST => $button(self::PASSED, __('Bestanden', 'taxmod-manufacturing'))
                . ListPage::dialogAction($form . '-failed', __('Nacharbeit …', 'taxmod-manufacturing'), sprintf(__('Nacharbeit: Posten #%1$d, %2$s', 'taxmod-manufacturing'), $lot, $board),
                    $text('note', __('Befund', 'taxmod-manufacturing'), $note, __('Das Stück wartet auf Nacharbeit; danach zurück in den Test.', 'taxmod-manufacturing')), 'action', self::FAILED, __('Eintragen', 'taxmod-manufacturing'), $form),
            Positions::REWORK => ListPage::dialogAction($form . '-retest', __('Zurück in Test …', 'taxmod-manufacturing'), sprintf(__('Zurück in Test: Posten #%1$d, %2$s', 'taxmod-manufacturing'), $lot, $board),
                    $text('retest_note', __('Was wurde nachgearbeitet? (optional)', 'taxmod-manufacturing'), '', __('Das Stück kommt ungetestet zurück in den Test.', 'taxmod-manufacturing')), 'action', self::RETEST, __('Zurück in Test', 'taxmod-manufacturing'), $form)
                . ListPage::dialogAction($form . '-scrap', __('Verschrotten …', 'taxmod-manufacturing'), sprintf(__('Verschrotten: Posten #%1$d, %2$s', 'taxmod-manufacturing'), $lot, $board),
                    $text('reason', __('Grund', 'taxmod-manufacturing'), '', __('Das Stück geht ganz als «Defekt/Ausschuss» aus dem Lager. Ein Vorgang, zurücknehmen über die Änderungsnummer.', 'taxmod-manufacturing')), 'action', self::SCRAP, __('Verschrotten', 'taxmod-manufacturing'), $form),
            default => '',
        };
        $label = $status === Positions::SCRAPPED ? '' : $this->service->lotLabelButton($lot);

        if ($inner === '') {
            return $label;
        }

        // One row of buttons, the label's beside the others; its dialog brings a form of its own, so it stands outside this one.
        return '<div class="taxmod-mf-judge"><form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field($this->nonce, '_wpnonce', true, false)
            . $this->hidden(['order' => (string) $order->id, 'tab' => $this->tab, 'selected[]' => (string) $lot], $form)
            . $inner . '</form>' . $label . '</div>';
    }

    /**
     * D-1425, his word 17.11. «der presi soll transparent sein»: the frozen price of a board, opened to its lines.
     *
     * @param array<string, mixed> $cost
     * @param array<int, string> $names
     */
    private function cost(array $cost, array $names): string
    {
        // D-1500: an amount through the one money block (D-1425 reckons in euro).
        $money  = static fn (float|int|string|null $n): string => ListPage::money((float) $n, 'EUR');
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
        $lines .= '<li>' . esc_html(sprintf(__('Bereitstellung: %1$s h × %2$s ÷ %3$d Stück = %4$s', 'taxmod-manufacturing'), $number($setup['hours'] ?? 0), $money($setup['wage'] ?? 0), (int) ($setup['divisor'] ?? 1), $money($setup['amount'] ?? 0))) . '</li>';
        /* translators: 1: minutes, 2: wage, 3: amount */
        $lines .= '<li>' . esc_html(sprintf(__('Arbeit: %1$s min × %2$s je Stunde = %3$s', 'taxmod-manufacturing'), $number($work['minutes'] ?? 0), $money($work['wage'] ?? 0), $money($work['amount'] ?? 0))) . '</li>';

        foreach ((array) ($cost['scrap'] ?? []) as $scrap) {
            $lines .= '<li>' . esc_html(sprintf(__('Ausschuss (nicht eingerechnet): %1$s × %2$s', 'taxmod-manufacturing'), $number($scrap['quantity'] ?? 0), $names[(int) ($scrap['thing'] ?? 0)] ?? '#' . (int) ($scrap['thing'] ?? 0))) . '</li>';
        }

        $total = $money($cost['total'] ?? 0) . (($cost['complete'] ?? true) ? '' : ' ' . __('(unvollständig)', 'taxmod-manufacturing'));

        return '<details><summary>' . esc_html($total) . '</summary><ul>' . $lines . '</ul></details>';
    }

    private function state(string $status): string
    {
        [$word, $class] = match ($status) {
            Positions::DONE     => [__('Fertig', 'taxmod-manufacturing'), 'ok'],
            Positions::REWORK   => [__('Nacharbeit', 'taxmod-manufacturing'), 'warn'],
            Positions::SCRAPPED => [__('Verschrottet', 'taxmod-manufacturing'), 'info'],
            default             => [__('In Test', 'taxmod-manufacturing'), 'info'],
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
