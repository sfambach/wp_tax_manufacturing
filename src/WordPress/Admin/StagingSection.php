<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Staging;
use TaxmodManufacturing\WordPress\ManufacturingService;

/**
 * The staging section of an order's page: one line per material with need, box, reserved and missing, beneath it the places
 * it can come from; one bar whose bulk choices act on the ticked rows (specification §3, D-1301, D-1387, D-1403).
 *
 * ```mermaid
 * flowchart TB
 *   M["material: Bedarf · Im Gebinde · Reserviert · Fehlt · Status"] --> R["↳ reserviert an Ort: Menge änderbar"]
 *   M --> F["↳ frei an Ort: Menge zum Nehmen"]
 *   M --> N["↳ nicht zugewiesen"]
 * ```
 *
 * His words 16.11.: «wir haben bestand in der projekt box somit sollte doch alles fein sein», «was soll dort heissen? nehmen
 * ist der status? das muss klarer werden», «reservierung ändern sollte auch möglich sein».
 *
 * @see /mnt/project-files/taxmod-fertigung/spezifikation-fertigungsprozess.md §3
 */
final class StagingSection
{
    public const FORM = 'taxmod-mf-pick';

    /** D-1403: the choices of the one bulk selection. */
    public const PICK    = 'taxmod_manufacturing_pick';
    public const RESERVE = 'taxmod_manufacturing_reserve';
    public const CHANGE  = 'taxmod_manufacturing_change';
    public const RELEASE = 'taxmod_manufacturing_release';

    public function __construct(
        private readonly ManufacturingService $service,
        private readonly string $nonce,
        private readonly string $action,
        private readonly string $tab,
    ) {
    }

    /**
     * @param array<int, list<array{position: int, thing: int, open: float, missing: float}>> $demand from Demand::of
     * @param array<int, list<array<string, mixed>>> $plain revision → positions as the parts list has them
     * @param array<int, float> $box thing → pieces of the box left for this order
     * @param list<array{line: int, thing: int, quantity: float, places: array<int, float>, position: int}> $reservations
     * @param array<int, string> $labels
     */
    public function render(BuildOrder $order, array $demand, array $plain, array $box, array $reservations, array $labels): string
    {
        if ($order->box === null || $order->isClosed()) {
            return '';
        }

        $things = [];

        foreach ($demand as $rows) {
            foreach ($rows as $row) {
                $things[] = $row['thing'];
            }
        }

        $canPick = $this->service->canPick();
        $lines   = Staging::lines($demand, $box, $reservations, $canPick ? $this->service->placesOf(array_values(array_unique($things)), $order->box) : [], $order->box);

        if ($lines === []) {
            return '';
        }

        $needs = [];

        foreach ($lines as $line) {
            foreach ($line['sources'] as $source) {
                if ($source['kind'] === 'none') {
                    $needs[$line['thing']] = $source['quantity'];
                }
            }
        }

        $cuts    = $canPick && $needs !== [] ? $this->service->cutSources($needs, $order->box) : [];
        $cutList = array_merge([], ...array_values($cuts));
        $ids     = [];

        foreach ($lines as $line) {
            $ids[] = $line['thing'];

            foreach ($line['sources'] as $source) {
                $ids[] = $source['thing'];
                $ids[] = (int) $source['place'];
            }
        }

        $missingIds = array_values(array_diff(array_unique(array_filter([...$ids, ...array_column($cutList, 'thing'), ...array_column($cutList, 'place')])), array_keys($labels)));
        $labels    += $missingIds === [] ? [] : $this->service->labelsOf($missingIds);
        [$refs, $first, $piece, $base, $pieces] = $this->positions($demand, $plain);
        $rows      = [];

        foreach ($lines as $band => $line) {
            $from   = count($rows);
            $rows[] = $this->materialRow($order, $line, $labels, $refs[$line['thing']] ?? [], $first[$line['thing']] ?? 0, $base);

            foreach ($line['sources'] as $source) {
                $rows[] = $this->sourceRow($order, $source, $labels, $piece[$line['thing']] ?? 0, $line['thing'], $pieces[$line['thing']] ?? []);
            }

            // D9: a strip found nowhere can be cut from a longer one of the same kind; the stock module books the cut.
            foreach ($cuts[$line['thing']] ?? [] as $source) {
                $key    = $line['thing'] . ':' . $source['lot'];
                $rows[] = [
                    '<input type="checkbox" name="selected[]" value="' . esc_attr('cut:' . $key) . '" form="' . self::FORM . '">',
                    '<span class="taxmod-mf-source">' . esc_html(sprintf(__('ablängen aus %1$s · %2$s', 'taxmod-manufacturing'), $labels[$source['thing']] ?? '#' . $source['thing'], $labels[$source['place']] ?? '#' . $source['place'])) . '</span>',
                    '', '', '', '',
                    esc_html($this->count($source['left'])),
                    '<input type="number" class="small-text" form="' . self::FORM . '" name="cutqty[' . esc_attr($key) . ']" min="0" step="1" value="' . esc_attr($this->count($needs[$line['thing']] ?? 0.0)) . '">',
                    $this->status('free', __('ablängen', 'taxmod-manufacturing')),
                    $this->rowAction(self::PICK, 'cut:' . $key, ListPage::ICON_TRANSFER, __('Ablängen und ins Gebinde umbuchen', 'taxmod-manufacturing')),
                ];
            }

            // Stefan 20.11. 20:57 «die Bestandszeilen immer in der gleichen Farbe wie die Position»: the stripes go per material
            // with its places, not per row; every other group carries the band mark the stylesheet colours.
            for ($i = $from; $band % 2 === 1 && $i < count($rows); $i++) {
                $rows[$i][0] = '<span class="taxmod-mf-band" hidden></span>' . $rows[$i][0];
            }
        }

        $num     = \Taxmod\WordPress\Draw\GridMarkup::NUMBER;
        $columns = [
            [''],
            [__('Material / Lagerort', 'taxmod-manufacturing')],
            [__('Bedarf', 'taxmod-manufacturing'), $num],
            [__('Im Gebinde', 'taxmod-manufacturing'), $num],
            [__('Reserviert', 'taxmod-manufacturing'), $num],
            [__('Fehlt', 'taxmod-manufacturing'), $num],
            [__('Bestand am Ort', 'taxmod-manufacturing'), $num],
            [__('Menge', 'taxmod-manufacturing')],
            [__('Status', 'taxmod-manufacturing')],
            [''],
        ];

        wp_enqueue_script('taxmod-mf-assign', $this->asset('assets/assign.js'), [], $this->version(), true);
        wp_enqueue_script('taxmod-mf-page', $this->asset('assets/manufacturing.js'), [], $this->version(), true);
        wp_enqueue_style('taxmod-mf-page', $this->asset('assets/manufacturing.css'), [], $this->version());

        return '<div class="taxmod-mf-pick">'
            . '<h3 class="taxmod-mf-printonly">' . esc_html(sprintf(__('Pickliste · %s', 'taxmod-manufacturing'), $order->label)) . '</h3>'
            . '<div class="taxmod-mf-noprint">' . $this->bar($order, $lines, $labels, $canPick) . '</div>'
            . ($canPick ? '' : '<p class="description">' . esc_html__('Das Lager liefert die Lagerorte und das Umbuchen noch nicht; die Liste zeigt nur, was fehlt.', 'taxmod-manufacturing') . '</p>')
            . ListPage::grid($columns, $rows, '', 'taxmod-mf-staging')
            . ($canPick ? $this->cutting($lines, $pieces, $labels) : '')
            . '<p class="description taxmod-mf-noprint">' . esc_html__('Je Material: Bedarf = Stück × Menge je Stück, noch nicht verbaut. Fehlt = Bedarf − im Gebinde − reserviert. Darunter die Lagerorte: Zeilen anhaken, Aktion wählen, ausführen. «Menge» ist, was die Aktion nimmt; bei «Reservierung ändern» die neue Menge (0 hebt auf).', 'taxmod-manufacturing') . '</p>'
            . '</div>';
    }

    /**
     * The one bar (D-1403): the acts on ticked rows as choices of the bulk selection; reordering and printing act on the page
     * and stand as buttons in the same row (architecture 16.11.).
     *
     * @param list<array{thing: int, sources: list<array{kind: string, quantity: float}>}> $lines
     * @param array<int, string> $labels
     */
    private function bar(BuildOrder $order, array $lines, array $labels, bool $canPick): string
    {
        $print = ListPage::button(__('Drucken', 'taxmod-manufacturing'), attributes: ['type' => 'button', 'data-taxmod-mf-print' => '']);

        if (! $canPick) {
            return ListPage::toolbar([$print]);
        }

        $list = '';

        foreach ($lines as $line) {
            foreach ($line['sources'] as $source) {
                if ($source['kind'] === 'none') {
                    $list .= '<li>' . esc_html($this->count($source['quantity']) . ' × ' . ($labels[$line['thing']] ?? '#' . $line['thing'])) . '</li>';
                }
            }
        }

        $reorder = $list !== '' && $this->service->canReorder()
            ? '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-mf-inline">' . wp_nonce_field($this->nonce, '_wpnonce', true, false)
                . $this->hidden(['action' => $this->action, 'order' => (string) $order->id, 'tab' => $this->tab])
                . ListPage::dialogAction('taxmod-mf-reorder-' . $order->id, __('Fehlendes nachbestellen', 'taxmod-manufacturing'), sprintf(__('Fehlendes nachbestellen: %s', 'taxmod-manufacturing'), $order->label),
                    '<p>' . esc_html__('Was an keinem Lagerort liegt, kommt als neuer Bedarf in den Einkauf. Packungsgröße und Händler wählst du dort.', 'taxmod-manufacturing') . '</p><ul>' . $list . '</ul>',
                    'do', 'reorder', __('Bedarf anlegen', 'taxmod-manufacturing'))
                . '</form>'
            : '';

        $reserving = $this->service->canReserve();
        $releasing = $this->service->canRelease();
        $rows      = array_sum(array_map(static fn (array $line): int => count($line['sources']), $lines));

        return ListPage::bulkBar(self::FORM, $this->nonce, array_filter([
            self::PICK    => __('Ins Gebinde umbuchen', 'taxmod-manufacturing'),
            self::RESERVE => $reserving ? __('Reservieren', 'taxmod-manufacturing') : '',
            self::CHANGE  => $releasing ? __('Reservierung ändern', 'taxmod-manufacturing') : '',
            self::RELEASE => $releasing ? __('Reservierung aufheben', 'taxmod-manufacturing') : '',
        ]), sprintf(_n('%d Zeile', '%d Zeilen', $rows, 'taxmod-manufacturing'), $rows), '<div class="alignleft actions">' . $reorder . ' ' . $print . '</div>', false)
            . $this->hidden(['order' => (string) $order->id, 'tab' => $this->tab], self::FORM);
    }

    /**
     * @param array{thing: int, needed: float, box: float, reserved: float, missing: float} $line
     * @param array<int, string> $labels
     * @param list<string> $refs
     * @param array<int, int> $base position → material of the parts list
     * @return list<string>
     */
    private function materialRow(BuildOrder $order, array $line, array $labels, array $refs, int $position, array $base): array
    {
        $covered = $line['missing'] <= 1e-9;
        $status  = $line['needed'] <= 0.0
            ? $this->status('info', __('nicht mehr nötig', 'taxmod-manufacturing'))
            : ($covered ? $this->status('ok', __('abgedeckt', 'taxmod-manufacturing')) : $this->status('warn', sprintf(__('fehlt %s', 'taxmod-manufacturing'), $this->count($line['missing']))));

        return [
            '',
            '<strong>' . $this->part($line['thing'], $labels, $order) . '</strong>' . ($refs === [] ? '' : ' <span class="description">' . esc_html(implode(', ', $refs)) . '</span>'),
            '<strong>' . esc_html($this->count($line['needed'])) . '</strong>',
            esc_html($this->count($line['box'])),
            esc_html($this->count($line['reserved'])),
            '<strong>' . esc_html($this->count($line['missing'])) . '</strong>',
            '',
            '',
            $status,
            $this->chooser($order, $line['thing'], $position, $labels, $line['missing'], $base),
        ];
    }

    /**
     * @param array{kind: string, thing: int, place: ?int, there: float, quantity: float, line: int, position: int} $source
     * @param array<int, string> $labels
     * @param array<int, int> $cuts D-1535 (P1): pins per piece → pieces of all this material's lengths
     * @return list<string>
     */
    private function sourceRow(BuildOrder $order, array $source, array $labels, int $piece, int $material, array $cuts = []): array
    {
        $place = $source['place'] === null ? '—' : esc_html($labels[$source['place']] ?? '#' . $source['place']);
        $name  = $source['thing'] !== $material ? ' · ' . $this->part($source['thing'], $labels, $order) . ' ' . esc_html__('(Ersatz)', 'taxmod-manufacturing') : '';

        if ($source['kind'] === 'none') {
            return ['', '<span class="taxmod-mf-source">' . esc_html__('an keinem Lagerort', 'taxmod-manufacturing') . '</span>', '', '', '', '', '',
                esc_html($this->count($source['quantity'])), $this->status('warn', __('nicht zugewiesen', 'taxmod-manufacturing')), ''];
        }

        $key = $source['kind'] === 'free'
            ? $source['thing'] . ':' . (int) $source['place']
            : 'res:' . $source['line'] . ':' . $source['thing'] . ':' . (int) $source['place'];
        $max = $source['kind'] === 'free' ? ' max="' . esc_attr($this->count($source['there'])) . '"' : '';

        return [
            '<input type="checkbox" name="selected[]" value="' . esc_attr($key) . '" form="' . self::FORM . '">',
            '<span class="taxmod-mf-source">' . $place . $name . '</span>',
            '', '', '', '',
            esc_html($this->count($source['there'])),
            '<input type="number" class="small-text" form="' . self::FORM . '" name="qty[' . esc_attr($key) . ']" min="0"' . $max . ' step="any" value="' . esc_attr($this->count($source['quantity'])) . '">'
                . ($piece > 0 ? '<input type="hidden" form="' . self::FORM . '" name="piece[' . esc_attr($key) . ']" value="' . esc_attr((string) $piece) . '">' : '')
                . ($cuts !== [] && $source['kind'] === 'free' ? '<input type="hidden" form="' . self::FORM . '" name="cuts[' . esc_attr($key) . ']" value="' . esc_attr(self::cutsText($cuts)) . '">' : '')
                . ($source['position'] > 0 ? '<input type="hidden" form="' . self::FORM . '" name="position[' . esc_attr($key) . ']" value="' . esc_attr((string) $source['position']) . '">' : ''),
            match ($source['kind']) {
                'reserved'     => $this->status('ok', __('reserviert', 'taxmod-manufacturing')),
                'reserved_box' => $this->status('ok', __('reserviert, im Gebinde', 'taxmod-manufacturing')),
                default        => $this->status('info', __('frei', 'taxmod-manufacturing')),
            },
            // Stefan 20.11. 20:39: «da ist dann kein Button, was man machen könnte» — per found place: take it for the order
            // (a reservation, it stays where it lies) or take it and book it into the box; the bulk choice keeps doing both for many.
            match ($source['kind']) {
                'free'     => ($this->service->canReserve() ? $this->rowAction(self::RESERVE, $key, 'paperclip', __('Zuweisen (bleibt am Lagerort)', 'taxmod-manufacturing')) . ' ' : '')
                    . $this->rowAction(self::PICK, $key, ListPage::ICON_TRANSFER, __('Zuweisen und ins Gebinde umbuchen', 'taxmod-manufacturing')),
                'reserved' => $this->rowAction(self::PICK, $key, ListPage::ICON_TRANSFER, __('Ins Gebinde umbuchen', 'taxmod-manufacturing')),
                default    => '',
            },
        ];
    }

    /**
     * One row's act as an icon square (D-1500): it submits the bulk form with its own choice and names its row in the address,
     * so the row counts whether it is ticked or not and its «Menge» is what is taken.
     */
    private function rowAction(string $action, string $key, string $icon, string $label): string
    {
        $button = ListPage::iconButton($icon, $label, '', 'action', $action, self::FORM);

        return preg_replace('/^<button /', '<button formaction="' . esc_url(add_query_arg('row', rawurlencode($key), admin_url('admin-post.php'))) . '" ', $button, 1) ?? $button;
    }

    /**
     * «Aus Bestand wählen» (D-1387): the stock's material search, prefilled with the position's material; it also changes
     * where a reservation comes from or which material (a substitute).
     *
     * @param array<int, string> $labels
     * @param array<int, int> $base position → material of the parts list
     */
    private function chooser(BuildOrder $order, int $thing, int $position, array $labels, float $missing, array $base): string
    {
        if (! has_filter('taxmod_stock_material_picker') || ! $this->service->canReserve()) {
            return '';
        }

        $wanted = $position === 0 ? $thing : ($base[$position] ?? $thing);
        $key    = 'taxmod-mf-assign-' . $order->id . '-' . $position . '-' . $thing;
        $picker = (string) apply_filters('taxmod_stock_material_picker', '', [
            'key'       => $key,
            'name'      => 'assign',
            'term'      => $labels[$wanted] ?? '',
            'preferred' => [$wanted],
            'except'    => (int) $order->box,
            'quantity'  => max($missing, 0.0),
        ]);

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-mf-noprint taxmod-mf-assign">' . wp_nonce_field($this->nonce, '_wpnonce', true, false)
            . $this->hidden(['action' => $this->action, 'order' => (string) $order->id, 'position' => (string) $position, 'tab' => $this->tab])
            . ListPage::dialogAction($key . '-dialog', __('Aus Bestand wählen', 'taxmod-manufacturing'), sprintf(__('Aus Bestand wählen: %s', 'taxmod-manufacturing'), $labels[$wanted] ?? '#' . $wanted), $picker, 'do', 'assign', __('Zuweisen', 'taxmod-manufacturing'))
            . '</form>';
    }

    /**
     * Per material: its reference designators, its first position (for «Aus Bestand wählen»), pins per piece (D-1398) and
     * the parts list's own material per position.
     *
     * @param array<int, list<array{position: int, thing: int}>> $demand
     * @param array<int, list<array<string, mixed>>> $plain
     * @return array{0: array<int, list<string>>, 1: array<int, int>, 2: array<int, int>, 3: array<int, int>, 4: array<int, array<int, int>>}
     */
    private function positions(array $demand, array $plain): array
    {
        $byId = [];

        foreach ($plain as $positions) {
            foreach ($positions as $position) {
                $byId[(int) $position['id']] = $position;
            }
        }

        $refs  = [];
        $first = [];
        $piece = [];
        $pins  = [];
        $base  = [];

        foreach ($byId as $id => $position) {
            $base[$id] = (int) $position['thing'];
        }

        foreach ($demand as $rows) {
            foreach ($rows as $row) {
                $at = $byId[$row['position']] ?? null;
                $first[$row['thing']] ??= $row['position'];

                if ($at !== null && trim((string) ($at['ref'] ?? '')) !== '') {
                    $refs[$row['thing']][] = trim((string) $at['ref']);
                }

                if ($at !== null && (int) ($at['piece'] ?? 0) > 0) {
                    $piece[$row['thing']] ??= (int) $at['piece'];
                    $pins[$row['position']] = (int) $at['piece'];
                }
            }
        }

        return [$refs, $first, $piece, $base, Staging::pieces($demand, $pins)];
    }

    /**
     * D-1535 (P1) «Leisten zuschneiden»: per strip counted in pins, the stock's one plan for all its missing pieces
     * at the first free place they fit — which strip (rest or whole) gives which pieces, what goes back, what is scrap.
     * He looks into the box of rests first anyway; the plan only says what to cut when nothing there fits.
     *
     * @param list<array{thing: int, sources: list<array{kind: string, place: ?int}>}> $lines
     * @param array<int, array<int, int>> $pieces material → pins per piece → pieces
     * @param array<int, string> $labels
     */
    private function cutting(array $lines, array $pieces, array $labels): string
    {
        $items = [];

        foreach ($lines as $line) {
            $cuts = $pieces[$line['thing']] ?? [];

            if ($cuts === []) {
                continue;
            }

            $places = [];

            foreach ($line['sources'] as $source) {
                if ($source['kind'] === 'free' && $source['place'] !== null) {
                    $places[] = (int) $source['place'];
                }
            }

            $plans = $this->service->pinCutPlans($line['thing'], array_values(array_unique($places)), $cuts);
            $want  = esc_html(sprintf(__('%1$s: %2$s', 'taxmod-manufacturing'), $labels[$line['thing']] ?? '#' . $line['thing'], self::shapes($cuts)));

            if ($plans === []) {
                $items[] = '<li>' . $want . ' · ' . $this->status('warn', __('an keinem Lagerort genug am Stück', 'taxmod-manufacturing')) . '</li>';
                continue;
            }

            $place = (int) array_key_first($plans);
            $plan  = $plans[$place];
            $steps = [];

            foreach ($plan['strips'] as $strip) {
                $steps[] = '<li>' . esc_html(sprintf(
                    $strip['whole'] ? __('ganze Leiste %1$d polig → %2$s', 'taxmod-manufacturing') : __('Rest %1$d polig → %2$s', 'taxmod-manufacturing'),
                    $strip['pins'],
                    self::shapes($strip['cuts']),
                ))
                    . ($strip['rest'] > 0 ? ' · ' . esc_html(sprintf(__('Rest %d polig zurück', 'taxmod-manufacturing'), $strip['rest'])) : '')
                    . ($strip['scrap'] > 0 ? ' · ' . $this->status('warn', sprintf(__('%d Pin Ausschuss', 'taxmod-manufacturing'), $strip['scrap'])) : '')
                    . '</li>';
            }

            $items[] = '<li>' . $want . ' · ' . esc_html(sprintf(__('aus %1$s, %2$d ganze Leiste(n) öffnen', 'taxmod-manufacturing'), $labels[$place] ?? '#' . $place, $plan['opened'])) . '<ul>' . implode('', $steps) . '</ul></li>';
        }

        if ($items === []) {
            return '';
        }

        return '<div class="taxmod-mf-cutting">'
            . '<h3>' . esc_html__('Leisten zuschneiden', 'taxmod-manufacturing') . '</h3>'
            . '<p class="description">' . esc_html__('Erst in der Schachtel mit Resten nach passenden Stücken sehen. Sonst so schneiden: längste Stücke zuerst, Reste vor ganzen Leisten, kein Rest unter 2 Pins. Gebucht wird beim Umbuchen der Zeile ins Gebinde.', 'taxmod-manufacturing') . '</p>'
            . '<ul>' . implode('', $items) . '</ul>'
            . '</div>';
    }

    /** @param array<int, int> $cuts pins per piece → pieces, shown «2 × 5 polig, 3 × 2 polig» */
    private static function shapes(array $cuts): string
    {
        $out = [];

        foreach ($cuts as $pins => $count) {
            $out[] = sprintf(__('%1$d × %2$d polig', 'taxmod-manufacturing'), $count, $pins);
        }

        return implode(', ', $out);
    }

    /** @param array<int, int> $cuts pins per piece → pieces, sent as «5:2,2:3» */
    private static function cutsText(array $cuts): string
    {
        $out = [];

        foreach ($cuts as $pins => $count) {
            $out[] = $pins . ':' . $count;
        }

        return implode(',', $out);
    }

    /** @param 'ok'|'warn'|'info'|'free' $kind */
    private function status(string $kind, string $text): string
    {
        return '<span class="taxmod-mf-status taxmod-mf-status-' . esc_attr($kind) . '">' . esc_html($text) . '</span>';
    }

    /**
     * A part's name, linked to its stock page (his word 2026-11-05; D-1179 origin arguments).
     *
     * @param array<int, string> $labels
     */
    private function part(int $thing, array $labels, BuildOrder $order): string
    {
        $name = esc_html($labels[$thing] ?? '#' . $thing);
        $url  = $this->service->stockUrl($thing);

        if ($url === null) {
            return $name;
        }

        $here = remove_query_arg(['taxmod_from', 'taxmod_from_label', 'taxmod_nav'], wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));

        return '<a href="' . esc_url(add_query_arg(['taxmod_from' => rawurlencode($here), 'taxmod_from_label' => rawurlencode($order->label)], $url)) . '">' . $name . '</a>';
    }

    private function count(float $n): string
    {
        return ListPage::number($n, 2);
    }

    /** @param array<string, string> $fields */
    private function hidden(array $fields, string $form = ''): string
    {
        $html = '';

        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden"' . ($form === '' ? '' : ' form="' . esc_attr($form) . '"') . ' name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }

        return $html;
    }

    private function asset(string $path): string
    {
        return plugins_url($path, dirname(__DIR__, 3) . '/wp_tax_manufacturing.php');
    }

    private function version(): ?string
    {
        return defined('TAXMOD_MANUFACTURING_VERSION') ? (string) TAXMOD_MANUFACTURING_VERSION : null;
    }
}
