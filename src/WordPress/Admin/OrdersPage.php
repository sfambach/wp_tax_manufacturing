<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Demand;
use TaxmodManufacturing\Core\PickList;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\WordPress\ManufacturingService;

/**
 * Fertigung › Aufträge (D-1142): the build orders in the shared list template (D-989). A row's details show the order's
 * box and, board by board, its step, note and parts list with needed · in the box · missing (D-1145, D-1146); every act on the
 * order stands behind a dialog (D-1063). Returns strings (CD-8).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/fertigung/konzept.md
 */
final class OrdersPage
{
    public const SLUG = \TaxmodManufacturing\WordPress\Plugin::ORDERS_PAGE;

    /**
     * His word 2026-11-14: the menu under Fertigung holds the points of the process bar. Each point is a module page of its own,
     * all drawn by this page; the open view follows from the page's slug, so menu and arrow are the same place.
     */
    public const VIEWS = [
        'boxes'          => self::SLUG,
        'order_planned'  => self::SLUG . '-angelegt',
        'order_staging'  => self::SLUG . '-bereitstellung',
        'order_building' => self::SLUG . '-in-fertigung',
        'order_done'     => self::SLUG . '-fertiggestellt',
        'cancelled'      => self::SLUG . '-abgebrochen',
    ];

    /** @return array<string, string> view → its name, the same for the menu point and the arrow */
    public static function viewLabels(): array
    {
        return [
            'boxes'          => __('Projektgebinde', 'taxmod-manufacturing'),
            'order_planned'  => __('Angelegt', 'taxmod-manufacturing'),
            'order_staging'  => __('Bereitstellung', 'taxmod-manufacturing'),
            'order_building' => __('In Fertigung', 'taxmod-manufacturing'),
            'order_done'     => __('Fertiggestellt', 'taxmod-manufacturing'),
            'cancelled'      => __('Abgebrochen', 'taxmod-manufacturing'),
        ];
    }

    /** Every view the list knows; «open», «done» and «all» stay for old links. */
    public const TABS = ['boxes', 'open', 'done', 'cancelled', 'all', 'order_planned', 'order_staging', 'order_building', 'order_done'];

    public const ACTION = 'taxmod_manufacturing';

    public const BULK_CANCEL = 'taxmod_manufacturing_cancel';

    public const BULK_REOPEN = 'taxmod_manufacturing_reopen';

    /** @var list<string> the admin-post actions this page answers */
    public const ACTIONS = [self::ACTION, self::BULK_CANCEL, self::BULK_REOPEN];

    private const NONCE = 'taxmod_manufacturing';

    private const FORM = 'taxmod-mf-bulk';

    /** @var array<int, string>|null read once per page, however many «+ Auftrag» dialogs it draws (CD-7) */
    private ?array $projects = null;

    /** @var array<int, array<int, string>>|null */
    private ?array $boards = null;

    public function __construct(private readonly ManufacturingService $service = new ManufacturingService())
    {
    }

    public function render(): string
    {
        $html = '<div class="wrap">' . $this->heading() . $this->notice();

        if (! class_exists(ListPage::class)) {
            return $html . '<div class="notice notice-error"><p>' . esc_html__('Der Taxonomy Modeller ist zu alt: die gemeinsame Listenansicht fehlt.', 'taxmod-manufacturing') . '</p></div></div>';
        }

        $missing = $this->service->structure()->missing();

        if ($missing !== []) {
            return $html . '<div class="notice notice-warning"><p>' . esc_html__('Das Modell der Fertigung ist noch nicht eingespielt. Es fehlen:', 'taxmod-manufacturing') . ' <code>' . esc_html(implode(', ', $missing)) . '</code></p></div></div>';
        }

        $asked = sanitize_key((string) ($_GET['tab'] ?? ''));

        if ($asked !== '' && ! in_array($asked, self::TABS, true)) {
            return $html . '<div class="notice notice-error"><p>' . esc_html__('Diese Ansicht gibt es nicht.', 'taxmod-manufacturing')
                . ' <a href="' . esc_url($this->url(['tab' => 'boxes'])) . '">' . esc_html__('Zu den Projektgebinden', 'taxmod-manufacturing') . '</a></p></div></div>';
        }

        try {
            $orders = $this->service->orders();
        } catch (ManufacturingError $error) {
            return $html . '<div class="notice notice-error"><p>' . esc_html($this->message($error)) . '</p></div></div>';
        }

        $detail = absint($_GET['auftrag'] ?? 0);

        if ($detail !== 0) {
            return $html . $this->detail($orders, $detail) . '</div>';
        }

        $tab   = $this->tab();
        $in    = static fn (string $step): int => count(array_filter($orders, static fn (BuildOrder $o): bool => $o->step === $step));
        // D-1300, D-1197, D-1325: the process as arrow tiles with their counts, starting at the project boxes an order grows
        // out of; «Abgebrochen» ends it beside «Fertiggestellt». «Offen» fell away: it counted what «Angelegt» counts (his word 13.11.).
        $boxes = $this->service->projectBoxes();
        $name  = self::viewLabels();
        $steps = array_values(array_filter([
            ['boxes', $name['boxes'], count($boxes)],
            ['order_planned', $name['order_planned'], $in('order_planned')],
            $this->service->structure()->hasValue('order_staging') ? ['order_staging', $name['order_staging'], $in('order_staging')] : null,
            ['order_building', $name['order_building'], $in('order_building')],
        ]));
        $ends   = [['order_done', $name['order_done'], $in('order_done')], ['cancelled', $name['cancelled'], $in('order_cancelled')]];
        // D-1346: the two ends side by side came with the modeller 0.2.86; before it «Abgebrochen» stands beside as a tab.
        $stacked = version_compare(\Taxmod\WordPress\Plugin::VERSION, '0.2.86', '>=');
        $steps[] = $stacked ? $ends : $ends[0];
        $beside  = $stacked ? [] : [$ends[1]];
        $shown = array_values(array_filter($orders, fn (BuildOrder $o): bool => $this->inTab($o, $tab)));

        $html .= $this->chooseBoards()
            . ListPage::toolbar([$this->createAction()])
            . ListPage::steps($steps, $tab, fn (string $slug): string => $this->url(['tab' => $slug]), $beside, ['all', __('Alle', 'taxmod-manufacturing'), count($orders)])
            . ListPage::bulkBar(self::FORM, self::NONCE, [self::BULK_CANCEL => __('Abbrechen', 'taxmod-manufacturing'), self::BULK_REOPEN => __('Wieder aufnehmen', 'taxmod-manufacturing')],
                sprintf(_n('%d Auftrag', '%d Aufträge', count($shown), 'taxmod-manufacturing'), count($shown)));

        if ($tab === 'boxes') {
            return $html . $this->boxList($orders, $boxes) . '</div>';
        }

        [$lists, $labels, $boardOf] = $this->context($shown);
        $reserved         = $this->service->reservedFor(array_map(static fn (BuildOrder $o): int => $o->id, $shown));
        $rows             = '';

        foreach ($shown as $order) {
            $rows .= $this->row($order, $labels, $lists, Demand::of($order->assemblies, $lists, $order->count, $this->service->available($order, $reserved[$order->id] ?? []), $boardOf));
        }

        if ($rows === '') {
            $rows = '<tr class="no-items"><td class="colspanchange" colspan="10">' . esc_html__('Keine Aufträge.', 'taxmod-manufacturing') . '</td></tr>';
        }

        return $html . ListPage::table([
            [__('Auftrag', 'taxmod-manufacturing')],
            [__('Projekt', 'taxmod-manufacturing')],
            [__('Stück', 'taxmod-manufacturing'), '5em'],
            [__('Status', 'taxmod-manufacturing'), '8em'],
            [__('Bestückt/Fertig', 'taxmod-manufacturing'), '9em'],
            [__('Gebinde', 'taxmod-manufacturing')],
            [__('Fehlende Pos.', 'taxmod-manufacturing'), '8em'],
            [__('Begonnen', 'taxmod-manufacturing'), '8em'],
        ], $rows) . '</div>';
    }

    /**
     * One order on its own page: head, box, and every board with its demand list (his word 2026-11-04 «mach doch lieber eine detailansicht»).
     *
     * @param list<BuildOrder> $orders
     */
    private function detail(array $orders, int $id): string
    {
        $found = array_values(array_filter($orders, static fn (BuildOrder $o): bool => $o->id === $id));
        $back  = '<p><a href="' . esc_url($this->url(['tab' => $this->tab()])) . '">← ' . esc_html__('Alle Aufträge', 'taxmod-manufacturing') . '</a></p>';

        if ($found === []) {
            return $back . '<div class="notice notice-error"><p>' . esc_html__('Diesen Auftrag gibt es nicht.', 'taxmod-manufacturing') . '</p></div>';
        }

        $order            = $found[0];
        [$lists, $labels, $boardOf] = $this->context([$order]);
        $demand           = Demand::of($order->assemblies, $lists, $order->count, $this->service->available($order), $boardOf);
        $facts            = [
            __('Projekt', 'taxmod-manufacturing')  => esc_html($labels[$order->project] ?? '#' . $order->project),
            __('Stück', 'taxmod-manufacturing')    => esc_html((string) $order->count),
            __('Status', 'taxmod-manufacturing')   => $this->stepCell($order, Demand::missingRows($demand)),
            __('Fehlende Pos.', 'taxmod-manufacturing') => esc_html($order->box === null ? '—' : (string) Demand::missingRows($demand)),
            __('Begonnen', 'taxmod-manufacturing') => esc_html($order->started ?? '—'),
            __('Fertig', 'taxmod-manufacturing')   => esc_html($order->finished ?? '—'),
        ];
        $head = '';

        foreach ($facts as $label => $value) {
            $head .= '<div><span class="description">' . esc_html($label) . '</span><br><strong>' . $value . '</strong></div>';
        }

        // His word 2026-11-13: the order's menu holds its steps; fitting opens only once the order is in production.
        $started = in_array($order->step, ['order_building', 'order_done'], true) || ($order->step === 'order_cancelled' && $order->started !== null);
        $views   = array_values(array_filter([
            ['order_planned', __('Angelegt', 'taxmod-manufacturing')],
            $this->service->structure()->hasValue('order_staging') ? ['order_staging', __('Bereitstellung', 'taxmod-manufacturing')] : null,
            ['order_building', __('Bestückung', 'taxmod-manufacturing')],
            ['order_done', __('Fertiggestellt', 'taxmod-manufacturing')],
        ]));
        // The order's own status stays marked apart from the open tab (architecture 13.11.).
        $views   = array_map(static fn (array $v): array => $v[0] === $order->step ? [$v[0], sprintf(__('%s · aktuell', 'taxmod-manufacturing'), $v[1])] : $v, $views);
        $keys    = array_column($views, 0);
        $view    = sanitize_key((string) ($_GET['view'] ?? ''));
        $view    = in_array($view, $keys, true) ? $view : (in_array($order->step, $keys, true) ? $order->step : 'order_planned');

        $list = $this->url(['tab' => $this->tab()]);
        $html = (method_exists(ListPage::class, 'back') ? '' : $back) . '<h2>' . esc_html($order->label) . '</h2>'
            . ListPage::toolbar([method_exists(ListPage::class, 'back') ? ListPage::back($list, __('Alle Aufträge', 'taxmod-manufacturing')) : '', $this->rowButtons($order, Demand::missingRows($demand))])
            . '<div style="display:flex;gap:2.5em;flex-wrap:wrap;margin:.5em 0 1em">' . $head . '</div>'
            . ListPage::steps($views, $view, fn (string $key): string => $this->url(['tab' => $this->tab(), 'auftrag' => $order->id, 'view' => $key]));

        if ($view === 'order_planned') {
            // Without the stage «Bereitstellung» in the model, the pick list stays where the box is chosen.
            return $html . $this->boxForm($order) . (in_array('order_staging', $keys, true) ? '' : $this->pickList($order, $demand));
        }

        if ($view === 'order_staging') {
            return $html . ($this->pickList($order, $demand) ?: '<p class="description">' . esc_html__('Nichts mehr bereitzustellen.', 'taxmod-manufacturing') . '</p>');
        }

        if ($view === 'order_building' && ! $started) {
            return $html . '<p class="description">' . esc_html__('Bestücken geht erst, wenn der Auftrag in Fertigung ist.', 'taxmod-manufacturing') . '</p>';
        }

        if ($view === 'order_done' && $order->step !== 'order_done') {
            return $html . '<p class="description">' . esc_html__('Der Auftrag ist noch nicht fertiggestellt.', 'taxmod-manufacturing') . '</p>';
        }

        foreach ($order->assemblies as $assembly) {
            $html .= $this->assemblyForm($order, $assembly, $labels, $lists[$assembly->revision] ?? [], $demand[$assembly->id] ?? []);
        }

        return $html;
    }

    /**
     * What the box still lacks and where it lies: tick what you put in, book it in one step, or print it (D-1155).
     *
     * @param array<int, list<array{thing: int, missing: float}>> $demand
     */
    private function pickList(BuildOrder $order, array $demand): string
    {
        if ($order->box === null || $order->isClosed() || Demand::missingRows($demand) === 0) {
            return '';
        }

        $count  = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
        $things = [];

        foreach ($demand as $rows) {
            foreach ($rows as $row) {
                $things[] = $row['thing'];
            }
        }

        $canPick = $this->service->canPick();
        $lines   = PickList::of($demand, $this->service->placesOf(array_values(array_unique($things)), $order->box));
        $needs   = [];

        foreach ($lines as $line) {
            if ($line['place'] === null) {
                $needs[$line['thing']] = $line['missing'];
            }
        }

        $cuts    = $canPick ? $this->service->cutSources($needs, $order->box) : [];
        $sources = array_merge([], ...array_values($cuts));
        $labels  = $this->service->labelsOf(array_values(array_unique(array_filter([...array_column($lines, 'thing'), ...array_column($lines, 'place'), ...array_column($sources, 'thing'), ...array_column($sources, 'place')]))));
        $body    = '';

        foreach ($lines as $line) {
            $key   = $line['thing'] . ':' . (int) $line['place'];
            $tick  = $canPick && $line['place'] !== null
                ? '<input type="checkbox" name="pick[]" value="' . esc_attr($key) . '" form="taxmod-mf-pick">'
                : '<span style="display:inline-block;width:1em;height:1em;border:1px solid #8c8f94"></span>';
            $take  = $canPick && $line['place'] !== null
                ? '<input type="number" class="small-text" form="taxmod-mf-pick" name="qty[' . esc_attr($key) . ']" min="0" max="' . esc_attr($count($line['there'])) . '" step="any" value="' . esc_attr($count($line['take'])) . '">'
                : esc_html($line['place'] === null ? '' : $count($line['take']));
            $body .= '<tr><td>' . $tick . '</td><td>' . $this->part($line['thing'], $labels, $order) . '</td>'
                . '<td>' . ($line['place'] === null
                    ? '<strong style="color:#b32d2e">' . esc_html($canPick ? __('nirgends am Lager', 'taxmod-manufacturing') : __('Lagerort unbekannt', 'taxmod-manufacturing')) . '</strong>'
                    : esc_html($labels[$line['place']] ?? '#' . $line['place'])) . '</td>'
                . '<td style="text-align:right">' . esc_html($line['place'] === null ? '' : $count($line['there'])) . '</td>'
                . '<td style="text-align:right">' . esc_html($count($line['missing'])) . '</td><td>' . $take . '</td></tr>';

            // D9: a strip found nowhere can be cut from a longer one of the same kind; the stock module books the cut.
            foreach ($line['place'] === null ? ($cuts[$line['thing']] ?? []) : [] as $source) {
                $key   = $line['thing'] . ':' . $source['lot'];
                $body .= '<tr><td><input type="checkbox" name="cut[]" value="' . esc_attr($key) . '" form="taxmod-mf-pick"></td>'
                    . '<td>' . esc_html(sprintf(__('ablängen aus %s', 'taxmod-manufacturing'), $labels[$source['thing']] ?? '#' . $source['thing'])) . '</td>'
                    . '<td>' . esc_html($labels[$source['place']] ?? '#' . $source['place']) . '</td>'
                    . '<td>' . esc_html($count($source['left'])) . '</td><td></td>'
                    . '<td><input type="number" class="small-text" form="taxmod-mf-pick" name="cutqty[' . esc_attr($key) . ']" min="0" step="1" value="' . esc_attr($count($line['missing'])) . '"></td></tr>';
            }
        }

        $nowhere = array_filter($lines, static fn (array $line): bool => $line['place'] === null);
        $reorder = '';

        if ($canPick && $nowhere !== [] && $this->service->canReorder()) {
            $list = '';

            foreach ($nowhere as $line) {
                $list .= '<li>' . esc_html($count($line['missing']) . ' × ' . ($labels[$line['thing']] ?? '#' . $line['thing'])) . '</li>';
            }

            $reorder = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
                . $this->hidden(['action' => self::ACTION, 'order' => (string) $order->id, 'tab' => $this->tab()])
                . ListPage::dialogAction('taxmod-mf-reorder-' . $order->id, __('Fehlendes nachbestellen', 'taxmod-manufacturing'), __('Fehlendes nachbestellen?', 'taxmod-manufacturing'),
                    '<p>' . esc_html__('Was an keinem Lagerort liegt, kommt als neuer Bedarf in den Einkauf. Packungsgröße und Händler wählst du dort.', 'taxmod-manufacturing') . '</p><ul>' . $list . '</ul>',
                    'do', 'reorder', __('Bedarf anlegen', 'taxmod-manufacturing'))
                . '</form>';
        }

        $note = $canPick ? '' : '<p class="description">' . esc_html__('Das Lager liefert die Lagerorte und das Umbuchen noch nicht; die Liste zeigt nur, was fehlt.', 'taxmod-manufacturing') . '</p>';

        return '<style>@media print{body *{visibility:hidden}.taxmod-mf-pick,.taxmod-mf-pick *{visibility:visible}.taxmod-mf-pick{position:absolute;left:0;top:0;width:100%}.taxmod-mf-noprint{display:none}}</style>'
            . '<div class="taxmod-mf-pick" style="border-top:1px solid #dcdcde;padding:.75em 0">'
            . '<h3>' . esc_html(sprintf(__('Pickliste · %s', 'taxmod-manufacturing'), $order->label)) . '</h3>'
            . '<div class="taxmod-mf-noprint">' . ListPage::toolbar([
                $reorder,
                $canPick ? '<button type="submit" class="button button-primary" form="taxmod-mf-pick">' . esc_html__('Abgehakte ins Gebinde umbuchen', 'taxmod-manufacturing') . '</button>' : '',
                // D-1301: or leave them where they lie, reserved for this order (Handlager).
                $canPick && $this->service->canReserve() ? '<button type="submit" class="button" form="taxmod-mf-pick" name="mode" value="reserve">' . esc_html__('Abgehakte reservieren', 'taxmod-manufacturing') . '</button>' : '',
                '<button type="button" class="button" onclick="window.print()">' . esc_html__('Drucken', 'taxmod-manufacturing') . '</button>',
            ]) . '</div>'
            . $note
            . '<form method="post" id="taxmod-mf-pick" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'do' => 'pick', 'order' => (string) $order->id, 'tab' => $this->tab()]) . '</form>'
            . '<table class="widefat striped" style="margin-top:.5em"><thead><tr><th style="width:2em"></th>'
            . '<th>' . esc_html__('Teil', 'taxmod-manufacturing') . '</th><th>' . esc_html__('Lagerort', 'taxmod-manufacturing') . '</th>'
            . '<th style="text-align:right">' . esc_html__('Dort', 'taxmod-manufacturing') . '</th><th style="text-align:right">' . esc_html__('Fehlt', 'taxmod-manufacturing') . '</th>'
            . '<th>' . esc_html__('Nehmen', 'taxmod-manufacturing') . '</th></tr></thead><tbody>' . $body . '</tbody></table></div>';
    }

    /**
     * A part's name, linked to its stock in the stock module (his word 2026-11-05: «zum Bestand springen»). The link names
     * this order as its origin, so the stock page leads back here (D-1179: arguments `taxmod_from`, `taxmod_from_label`).
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

    /**
     * Parts lists, labels and the board record of each revision for the given orders, read once (CD-7).
     *
     * @param list<BuildOrder> $orders
     * @return array{0: array<int, list<array{id: int, thing: int, count: float, fitting: string, ref: string}>>, 1: array<int, string>, 2: array<int, int>}
     */
    private function context(array $orders): array
    {
        $revisions = [];

        foreach ($orders as $order) {
            foreach ($order->assemblies as $assembly) {
                $revisions[$assembly->revision] = true;
            }
        }

        $lists   = $this->service->partsLists(array_keys($revisions));
        $boardOf = $this->service->boardsOfRevisions(array_keys($revisions));
        $things  = array_values($boardOf);

        foreach ($lists as $positions) {
            foreach ($positions as $position) {
                $things[] = $position['thing'];
            }
        }

        $labels = $this->service->labelsOf(array_values(array_filter(array_unique([...array_keys($revisions), ...$things, ...array_map(static fn (BuildOrder $o): int => $o->project, $orders), ...array_filter(array_map(static fn (BuildOrder $o): ?int => $o->box, $orders))]))));

        return [$lists, $labels, $boardOf];
    }

    /**
     * admin-post: capability → nonce → validate → sanitize → act → back to the page (CD-5). As an act (D-1254): an error the
     * page does not name itself becomes a message in the message field with the way back, never an empty page.
     */
    public function handle(string $action): void
    {
        $back = $this->url(['tab' => sanitize_key((string) ($_POST['tab'] ?? ''))]);

        if (! method_exists(ListPage::class, 'act')) {
            $this->act($action);

            return;
        }

        ListPage::act(function () use ($action): string {
            $this->act($action);

            return '';
        }, $back);
    }

    private function act(string $action): void
    {
        if (! current_user_can('manage_options')) {
            $this->refuse(__('Keine Berechtigung.', 'taxmod-manufacturing'));
        }

        if (method_exists(ListPage::class, 'nonce')) {
            ListPage::nonce(self::NONCE);
        } elseif (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), self::NONCE)) {
            $this->refuse(__('Das Formular ist abgelaufen. Bitte noch einmal.', 'taxmod-manufacturing'));
        }

        $today = current_time('Y-m-d');
        $tab   = sanitize_key((string) ($_POST['tab'] ?? ''));
        $back  = ['tab' => $tab];

        try {
            if ($action === self::BULK_CANCEL || $action === self::BULK_REOPEN) {
                foreach (array_filter(array_map('absint', (array) ($_POST['selected'] ?? []))) as $order) {
                    $this->service->setStep($order, $action === self::BULK_CANCEL ? 'order_cancelled' : 'reopen', $today);
                }

                $back['msg'] = $action === self::BULK_CANCEL ? 'cancelled' : 'reopened';
            } else {
                $do    = sanitize_key((string) ($_POST['do'] ?? ''));
                $order = absint($_POST['order'] ?? 0);

                $back['msg'] = match ($do) {
                    'create'   => $this->create($back),
                    'assembly' => $this->updateAssembly($order, $today, $back),
                    'box'      => $this->box($order, $back),
                    'pick'     => $this->pick($order, $today, $back),
                    'reorder'  => $this->reorder($order, $back),
                    'staging'  => $this->step($order, 'order_staging', $today, $back),
                    'clear'    => $this->clear($order, $today, $back),
                    'building' => $this->toBuilding($order, $today, $back),
                    'done'     => $this->step($order, 'order_done', $today, $back),
                    'cancel'   => $this->step($order, 'order_cancelled', $today, $back),
                    'reopen'   => $this->step($order, 'reopen', $today, $back),
                    default    => throw ManufacturingError::unknownStatus($do),
                };
            }
        } catch (ManufacturingError $error) {
            $back['err'] = rawurlencode($this->message($error));
            unset($back['msg']);
        }

        wp_safe_redirect($this->url($back));
        exit;
    }

    /** @param array<string, string|int> $back */
    private function create(array &$back): string
    {
        $project = absint($_POST['project'] ?? 0);

        if ($project === 0) {
            throw ManufacturingError::projectWithoutBoards(0);
        }

        $count = sanitize_text_field(wp_unslash((string) ($_POST['count'] ?? '1')));

        if (! isset($_POST['choosing'])) {
            $back['neu']   = $project;
            $back['count'] = $count;

            return '';
        }

        $chosen = array_values(array_filter(array_map('absint', (array) wp_unslash($_POST['boards'] ?? []))));
        $label  = $this->service->labelsOf([$project])[$project] ?? '#' . $project;
        $box    = absint($_POST['box'] ?? 0);
        $id     = $this->service->create($project, $count, $box > 0 && isset($this->service->boxesOf($project)[$box]) ? $box : null, sprintf(__('Fertigung %1$s, %2$s', 'taxmod-manufacturing'), $label, current_time('Y-m-d')), $chosen);

        $back['auftrag'] = $id;
        $back['tab']     = 'open';

        return 'created';
    }

    /** @param array<string, string|int> $back */
    private function updateAssembly(int $order, string $today, array &$back): string
    {
        $fitted = [];

        foreach ((array) ($_POST['fitted'] ?? []) as $position => $pieces) {
            $fitted[absint($position)] = max(0.0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) $pieces))));
        }

        $unused = array_values(array_filter(array_map('absint', (array) ($_POST['unused'] ?? []))));
        $number = static fn (string $key): ?int => isset($_POST[$key]) && $_POST[$key] !== '' ? absint($_POST[$key]) : null;
        $note   = isset($_POST['note']) ? sanitize_text_field(wp_unslash((string) $_POST['note'])) : null;

        $scrap = [];

        foreach ((array) ($_POST['scrap'] ?? []) as $position => $pieces) {
            $scrap[absint($position)] = max(0.0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) $pieces))));
        }

        $booked       = $this->service->updateAssembly($order, absint($_POST['assembly'] ?? 0), $fitted, $unused, $number('tested'), $number('done'), $note, $today, absint($_POST['sparte'] ?? 0) ?: null);
        $back['auftrag'] = $order;
        // After the counts, which check themselves before booking anything: scrap is its own booking (D-1302).
        $this->service->bookScrap($order, absint($_POST['assembly'] ?? 0), $scrap, sanitize_text_field(wp_unslash((string) ($_POST['scrap_reason'] ?? ''))), $today);


        return ['booked' => 'saved_booked', 'unbooked' => 'saved_unbooked'][$booked] ?? 'saved';
    }

    /**
     * The order's shortfall as read now, not as the page showed it: what lies nowhere (D-1156).
     *
     * @param array<string, string|int> $back
     */
    private function reorder(int $order, array &$back): string
    {
        $built            = $this->service->order($order);
        [$lists, , $boardOf] = $this->context([$built]);
        $demand           = Demand::of($built->assemblies, $lists, $built->count, $this->service->available($built), $boardOf);
        $things           = array_values(array_unique(array_merge(...array_map(static fn (array $rows): array => array_column($rows, 'thing'), array_values($demand)) ?: [[]])));
        $quantities       = [];
        $back['auftrag']  = $order;

        foreach (PickList::of($demand, $this->service->placesOf($things, (int) $built->box)) as $line) {
            if ($line['place'] === null) {
                $quantities[$line['thing']] = $line['missing'];
            }
        }

        if ($quantities === []) {
            return 'nothing_missing';
        }

        $this->service->reorder($order, $quantities);

        return 'reordered';
    }

    /** @param array<string, string|int> $back */
    private function pick(int $order, string $today, array &$back): string
    {
        $quantities = (array) ($_POST['qty'] ?? []);
        $lines      = [];

        foreach ((array) ($_POST['pick'] ?? []) as $key) {
            $key = sanitize_text_field(wp_unslash((string) $key));

            if (preg_match('/^(\d+):(\d+)$/', $key, $match) !== 1) {
                continue;
            }

            $qty = (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) ($quantities[$key] ?? '0'))));

            if ($qty > 0.0) {
                $lines[] = ['thing' => (int) $match[1], 'from' => (int) $match[2], 'qty' => $qty];
            }
        }

        $cutQty = (array) ($_POST['cutqty'] ?? []);
        $cuts   = [];

        foreach ((array) ($_POST['cut'] ?? []) as $key) {
            $key = sanitize_text_field(wp_unslash((string) $key));
            $qty = (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) ($cutQty[$key] ?? '0'))));

            if (preg_match('/^(\d+):(\d+)$/', $key, $match) === 1 && $qty > 0.0) {
                $cuts[] = ['from' => (int) $match[2], 'thing' => (int) $match[1], 'pieces' => $qty];
            }
        }

        $back['auftrag'] = $order;

        if ($lines === [] && $cuts === []) {
            return 'nothing_picked';
        }

        if ($cuts !== []) {
            $this->service->bookCut($order, $cuts, $today);
        }

        if ($lines !== [] && sanitize_key((string) ($_POST['mode'] ?? '')) === 'reserve') {
            $this->service->reserve($order, $lines, $today);

            return 'reserved';
        }

        if ($lines !== []) {
            $this->service->bookPick($order, $lines, $today);
        }

        return 'picked';
    }

    /** @param array<string, string|int> $back */
    private function box(int $order, array &$back): string
    {
        $this->service->setBox($order, absint($_POST['box'] ?? 0));
        $back['auftrag'] = $order;

        return 'box';
    }

    /** @param array<string, string|int> $back */
    private function clear(int $order, string $today, array &$back): string
    {
        $this->service->clearBox($order, $today);
        $back['auftrag'] = $order;

        return 'cleared';
    }

    /**
     * D-1301: into building; the parts still missing are read now, and with any missing the reason must be given.
     *
     * @param array<string, string|int> $back
     */
    private function toBuilding(int $order, string $today, array &$back): string
    {
        $built           = $this->service->order($order);
        [$lists, , $boardOf] = $this->context([$built]);
        $missing         = Demand::missingRows(Demand::of($built->assemblies, $lists, $built->count, $this->service->available($built), $boardOf));
        $back['auftrag'] = $order;

        $this->service->setStep($order, 'order_building', $today, $missing, sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? ''))));

        return 'building';
    }

    /**
     * @param 'order_staging'|'order_done'|'order_cancelled'|'reopen' $to
     * @param array<string, string|int> $back
     */
    private function step(int $order, string $to, string $today, array &$back): string
    {
        $this->service->setStep($order, $to, $today);
        if (sanitize_key((string) ($_POST['view'] ?? '')) === 'detail') {
            $back['auftrag'] = $order;
        }

        return ['order_staging' => 'staging', 'order_done' => 'closed', 'order_cancelled' => 'cancelled', 'reopen' => 'reopened'][$to];
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, list<array{id: int, thing: int, count: float, fitting: string, ref: string}>> $lists
     * @param array<int, list<array{position: int, thing: int, needed: float, present: float, missing: float, optional: bool, unused: bool}>> $demand
     */
    private function row(BuildOrder $order, array $labels, array $lists, array $demand): string
    {
        $cells = [
            '<strong><a href="' . esc_url($this->url(['auftrag' => $order->id, 'tab' => $this->tab()])) . '">' . esc_html($order->label) . '</a></strong>',
            esc_html($labels[$order->project] ?? '#' . $order->project),
            esc_html((string) $order->count),
            $this->stepCell($order, $order->box === null ? 0 : Demand::missingRows($demand)),
            esc_html(implode(' · ', array_map(static fn (Assembly $a): string => Demand::boardsFitted($demand[$a->id] ?? []) . '/' . $a->done, $order->assemblies))),
            $order->box === null ? '<em>' . esc_html__('keins', 'taxmod-manufacturing') . '</em>' : esc_html($labels[$order->box] ?? '#' . $order->box),
            esc_html($order->box === null ? '—' : (string) Demand::missingRows($demand)),
            esc_html($order->started ?? '—'),
        ];

        $details = '';

        foreach ($order->assemblies as $assembly) {
            $rows     = $demand[$assembly->id] ?? [];
            $details .= '<li>' . esc_html($labels[$assembly->revision] ?? '#' . $assembly->revision) . ': '
                . esc_html(sprintf(__('%1$d von %2$d bestückt, %3$d fertig', 'taxmod-manufacturing'), Demand::boardsFitted($rows), $order->count, $assembly->done))
                . ($order->box !== null && Demand::missingRows([$rows]) > 0 ? ' · <strong style="color:#b32d2e">' . esc_html(sprintf(__('%d Positionen fehlen', 'taxmod-manufacturing'), Demand::missingRows([$rows]))) . '</strong>' : '') . '</li>';
        }

        $details = '<ul style="margin:.5em 0">' . $details . '</ul><p><a class="button" href="' . esc_url($this->url(['auftrag' => $order->id, 'tab' => $this->tab()])) . '">' . esc_html__('Öffnen', 'taxmod-manufacturing') . '</a></p>';

        return ListPage::row(self::FORM, (string) $order->id, $cells, $this->rowButtons($order, $order->box === null ? 0 : Demand::missingRows($demand)), $details);
    }

    /** The order's box: everything lying on it and in it counts as there (his word 2026-11-04). */
    private function boxForm(BuildOrder $order): string
    {
        $options = '<option value="0">' . esc_html__('— kein Gebinde —', 'taxmod-manufacturing') . '</option>';

        foreach ($this->service->boxesOf($order->project) as $id => $label) {
            $options .= '<option value="' . esc_attr((string) $id) . '"' . selected($id, $order->box, false) . '>' . esc_html($label) . '</option>';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . $this->hidden(['action' => self::ACTION, 'do' => 'box', 'order' => (string) $order->id, 'tab' => $this->tab()])
            . ListPage::form([[
                'label'   => __('Gebinde', 'taxmod-manufacturing'),
                'control' => '<select name="box" id="taxmod-mf-box-' . esc_attr((string) $order->id) . '">' . $options . '</select>',
                'hint'    => __('Was im Gebinde und darunter liegt, zählt als vorhanden. Projekt-Gebinde legst du im Lager an.', 'taxmod-manufacturing'),
                'for'     => 'taxmod-mf-box-' . $order->id,
            ]], $this->saveButton()) . '</form>';
    }

    private function rowButtons(BuildOrder $order, int $missing = 0): string
    {
        $buttons = '';

        if ($order->step === 'order_planned' && $this->service->structure()->hasValue('order_staging')) {
            $buttons .= $this->dialogForm('staging', $order, __('In Bereitstellung geben', 'taxmod-manufacturing'), __('In Bereitstellung geben?', 'taxmod-manufacturing'),
                esc_html__('Die Teile werden jetzt ins Gebinde umgelagert oder reserviert.', 'taxmod-manufacturing'));
        }

        if (in_array($order->step, ['order_planned', 'order_staging'], true)) {
            $buttons .= $missing === 0 || $order->box === null
                ? $this->dialogForm('building', $order, __('In Fertigung geben', 'taxmod-manufacturing'), __('In Fertigung geben?', 'taxmod-manufacturing'),
                    esc_html__('Alle Teile sind bereit.', 'taxmod-manufacturing'))
                : $this->dialogForm('building', $order, __('Trotzdem in Fertigung geben …', 'taxmod-manufacturing'), __('Trotzdem in Fertigung geben?', 'taxmod-manufacturing'),
                    esc_html(sprintf(_n('Es fehlt noch %d Position. Der Auftrag bleibt mit «Fehlteile» markiert, bis alles da ist.', 'Es fehlen noch %d Positionen. Der Auftrag bleibt mit «Fehlteile» markiert, bis alles da ist.', $missing, 'taxmod-manufacturing'), $missing))
                    . '</p><p><label>' . esc_html__('Grund', 'taxmod-manufacturing') . '<br><input type="text" class="regular-text" name="reason" required></label>');
        }

        if (! $order->isClosed() && $order->isFinished()) {
            $buttons .= $this->dialogForm('done', $order, __('Abschließen', 'taxmod-manufacturing'), __('Auftrag abschließen?', 'taxmod-manufacturing'),
                esc_html__('Alle Platinen aller Baugruppen sind fertig. Der Auftrag wird als fertig markiert; «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'));
        }

        if ($order->box !== null && has_filter(ManufacturingService::RETURN_FILTER)) {
            $buttons .= $this->dialogForm('clear', $order, __('Box leer räumen', 'taxmod-manufacturing'), __('Gebinde leer räumen?', 'taxmod-manufacturing'),
                esc_html__('Alles im Gebinde geht zurück an den Platz, von dem es kam. Ein Vorgang, rückgängig über Storno.', 'taxmod-manufacturing'));
        }

        if ($order->isClosed()) {
            return $buttons . $this->dialogForm('reopen', $order, __('Wieder aufnehmen', 'taxmod-manufacturing'), __('Auftrag wieder aufnehmen?', 'taxmod-manufacturing'),
                esc_html__('Der Auftrag kommt zurück in «Offen», mit dem Stand seiner Platinen.', 'taxmod-manufacturing'));
        }

        return $buttons . $this->dialogForm('cancel', $order, __('Abbrechen', 'taxmod-manufacturing'), __('Auftrag abbrechen?', 'taxmod-manufacturing'),
            esc_html__('Nichts wird gelöscht; «Wieder aufnehmen» holt ihn zurück.', 'taxmod-manufacturing'));
    }

    /**
     * @param array<int, string> $labels
     * @param list<array{id: int, thing: int, count: float, fitting: string, ref: string}> $positions
     * @param list<array{position: int, thing: int, count: float, needed: float, boards: int, open: float, present: float, missing: float, optional: bool, unused: bool}> $rows
     */
    private function assemblyForm(BuildOrder $order, Assembly $assembly, array $labels, array $positions, array $rows): string
    {
        $refs   = array_column($positions, 'ref', 'id');
        $count  = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
        $boxed  = $order->box !== null;
        $pieces = $order->count;
        $body   = '';
        $form   = 'taxmod-mf-a' . $assembly->id;
        $scrap  = $boxed && ! $order->isClosed() && $this->service->canScrap();

        foreach ($rows as $row) {
            $fit = $row['position'] === 0 || $row['unused']
                ? esc_html($count($row['boards'] * $row['count']))
                : '<input type="number" class="small-text taxmod-mf-fit" name="fitted[' . esc_attr((string) $row['position']) . ']" min="0" max="' . esc_attr($count($row['needed'])) . '" step="' . esc_attr($count($row['count'])) . '" value="' . esc_attr($count($row['boards'] * $row['count'])) . '" data-full="' . esc_attr($count($row['needed'])) . '">'
                    . ' <button type="button" class="button button-small" onclick="var i=this.parentNode.querySelector(\'.taxmod-mf-fit\');i.value=i.dataset.full">' . esc_html__('voll', 'taxmod-manufacturing') . '</button>';
            $use = $row['optional']
                ? '<label><input type="checkbox" name="unused[]" value="' . esc_attr((string) $row['position']) . '"' . checked($row['unused'], true, false) . '> ' . esc_html__('nicht verwenden', 'taxmod-manufacturing') . '</label>'
                : '';
            $body .= '<tr' . ($row['unused'] ? ' style="opacity:.55"' : '') . '>'
                . '<td>' . esc_html($row['position'] === 0 ? __('Platine', 'taxmod-manufacturing') : (string) ($refs[$row['position']] ?? '')) . '</td>'
                . '<td>' . $this->part($row['thing'], $labels, $order) . '</td>'
                . '<td style="text-align:right">' . esc_html($count($row['needed'])) . '</td>'
                . '<td style="white-space:nowrap">' . $fit . '</td>'
                . '<td style="text-align:right">' . esc_html($boxed ? $count($row['present']) : '—') . '</td>'
                . '<td style="text-align:right">' . ($boxed && $row['missing'] > 0.0 ? '<strong style="color:#b32d2e">' . esc_html($count($row['missing'])) . '</strong>' : '') . '</td>'
                . '<td>' . $use . '</td>'
                . ($scrap ? '<td><input type="number" class="small-text" name="scrap[' . esc_attr((string) $row['position']) . ']" min="0" step="1" value="" aria-label="' . esc_attr__('Ausschuss', 'taxmod-manufacturing') . '"></td>' : '')
                . '</tr>';
        }

        $table = '<table class="widefat striped" style="margin-top:.5em"><thead><tr>'
            . '<th>' . esc_html__('Referenz', 'taxmod-manufacturing') . '</th><th>' . esc_html__('Teil', 'taxmod-manufacturing') . '</th>'
            . '<th style="text-align:right">' . esc_html__('Bedarf', 'taxmod-manufacturing') . '</th><th>' . esc_html__('Bestückt (Teile)', 'taxmod-manufacturing') . '</th>'
            . '<th style="text-align:right">' . esc_html__('Im Gebinde', 'taxmod-manufacturing') . '</th><th style="text-align:right">' . esc_html__('Fehlt', 'taxmod-manufacturing') . '</th>'
            . '<th>' . esc_html__('Option', 'taxmod-manufacturing') . '</th>'
            . ($scrap ? '<th title="' . esc_attr__('Heruntergefallen, defekt oder falsch bestückt: wird als Ausschuss aus dem Gebinde gebucht.', 'taxmod-manufacturing') . '">' . esc_html__('Ausschuss', 'taxmod-manufacturing') . '</th>' : '')
            . '</tr></thead><tbody>' . $body . '</tbody></table>';

        $fittedBoards = Demand::boardsFitted($rows);
        // «alle auf n Platinen»: n × the parts per board in every field of this form.
        $fill = '<input type="number" class="small-text" min="0" max="' . esc_attr((string) $pieces) . '" value="' . esc_attr((string) $pieces) . '" aria-label="' . esc_attr__('Anzahl Platinen', 'taxmod-manufacturing') . '"> '
            . '<button type="button" class="button" onclick="var n=this.previousElementSibling.value;this.closest(\'form\').querySelectorAll(\'.taxmod-mf-fit\').forEach(function(i){i.value=n*parseFloat(i.step)})">' . esc_html__('Alle auf n Platinen', 'taxmod-manufacturing') . '</button>';
        $counts = $this->service->structure()->hasField('assembly_tested')
            ? '<label>' . esc_html__('Geprüft', 'taxmod-manufacturing') . ' <input type="number" class="small-text" name="tested" min="0" max="' . esc_attr((string) $pieces) . '" value="' . esc_attr((string) $assembly->tested) . '"></label>'
                . '<label title="' . esc_attr__('Nur geprüfte Platinen können fertig sein; sie kommen als eigenes Material ins Gebinde.', 'taxmod-manufacturing') . '">' . esc_html__('Fertig', 'taxmod-manufacturing') . ' <input type="number" class="small-text" name="done" min="0" max="' . esc_attr((string) $pieces) . '" value="' . esc_attr((string) $assembly->done) . '"></label>'
            : '';

        return '<form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '" style="border-top:1px solid #dcdcde;padding:.75em 0">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . $this->hidden(['action' => self::ACTION, 'do' => 'assembly', 'order' => (string) $order->id, 'assembly' => (string) $assembly->id, 'tab' => $this->tab()])
            . '<h3>' . esc_html($labels[$assembly->revision] ?? '#' . $assembly->revision)
            . ' · ' . esc_html(sprintf(__('%1$d von %2$d bestückt', 'taxmod-manufacturing'), $fittedBoards, $pieces)) . '</h3>'
            . ListPage::toolbar([$this->saveButton(), $fill])
            . '<div style="display:flex;align-items:center;gap:1em;flex-wrap:wrap">'
            . $counts . $this->sparteChooser($assembly, $form)
            . ($scrap ? '<label>' . esc_html__('Grund Ausschuss (optional)', 'taxmod-manufacturing') . ' <input type="text" name="scrap_reason" list="taxmod-mf-scrap-reasons"></label><datalist id="taxmod-mf-scrap-reasons"><option value="' . esc_attr__('heruntergefallen', 'taxmod-manufacturing') . '"><option value="' . esc_attr__('defekt', 'taxmod-manufacturing') . '"><option value="' . esc_attr__('falsch bestückt', 'taxmod-manufacturing') . '"></datalist>' : '')
            . ($this->service->structure()->hasField('assembly_note') ? '<label>' . esc_html__('Hinweis', 'taxmod-manufacturing') . ' <input type="text" class="regular-text" name="note" value="' . esc_attr($assembly->note) . '"></label>' : '')
            . '</div>'
            . $table . '</form>';
    }

    /**
     * Only before the first finished board of a revision: in which Hardware-Sparte its record is made (D-1261). The modeller's
     * node chooser, rooted at Hardware, with the Sparte of another revision of the board preselected.
     */
    private function sparteChooser(Assembly $assembly, string $form): string
    {
        $s = $this->service->structure();

        if (! $this->service->canReceive() || $this->service->existingMaterial($assembly->revision) !== null) {
            return '';
        }

        $chooser = apply_filters(\Taxmod\WordPress\Plugin::NODE_CHOOSER_FILTER, '', [
            'name'        => 'sparte',
            'root'        => $s->node('hardware'),
            'preselected' => $this->service->suggestedSparte($assembly->revision) ?? 0,
            'form'        => $form,
        ]);

        return '<label>' . esc_html__('Hardware-Sparte', 'taxmod-manufacturing') . ' ' . (is_string($chooser) ? $chooser : '') . '</label>';
    }

    /**
     * D-1325 (his word: «bei der Fertigung mit dem Projektgebinde starten»): the project boxes, each with its project, what
     * lies in it, its open orders, and «+ Auftrag» with project and box filled in.
     *
     * @param list<BuildOrder> $orders
     * @param array<int, array{project: int, label: string, pieces: ?float}> $boxes place → box, read once by the page
     */
    private function boxList(array $orders, array $boxes): string
    {
        $labels = $this->service->labelsOf(array_values(array_unique(array_column($boxes, 'project'))));
        $count  = static fn (float $n): string => rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
        $rows   = '';

        foreach ($boxes as $place => $box) {
            $open   = array_filter($orders, static fn (BuildOrder $o): bool => $o->box === $place && ! $o->isClosed());
            $links  = implode(', ', array_map(fn (BuildOrder $o): string => '<a href="' . esc_url($this->url(['auftrag' => $o->id])) . '">' . esc_html($o->label) . '</a>', $open));
            $rows  .= ListPage::row('taxmod-mf-boxes', (string) $place, [
                esc_html($box['label']),
                esc_html($labels[$box['project']] ?? '#' . $box['project']),
                esc_html($box['pieces'] === null ? '—' : $count($box['pieces'])),
                $links === '' ? '—' : $links,
            ], $this->createAction($box['project'], $place), '');
        }

        if ($rows === '') {
            $rows = '<tr class="no-items"><td class="colspanchange" colspan="6">' . esc_html__('Keine Projektgebinde. Ein Gebinde wird zum Projektgebinde, wenn es im Lager ein Projekt bekommt.', 'taxmod-manufacturing') . '</td></tr>';
        }

        return ListPage::table([
            [__('Gebinde', 'taxmod-manufacturing')],
            [__('Projekt', 'taxmod-manufacturing')],
            [__('Inhalt (Stück)', 'taxmod-manufacturing'), '8em'],
            [__('Offene Aufträge', 'taxmod-manufacturing')],
        ], $rows);
    }

    /** «+ Auftrag»; from a project box (D-1325) with its project and the box filled in. */
    private function createAction(int $project = 0, int $box = 0): string
    {
        $options = '<option value="">' . esc_html__('— Projekt wählen —', 'taxmod-manufacturing') . '</option>';

        $this->projects ??= $this->service->projects();
        $this->boards   ??= $this->service->boardsOfProjects(array_keys($this->projects));
        $projects       = $project > 0 ? array_intersect_key($this->projects, [$project => true]) : $this->projects;

        foreach ($projects as $id => $label) {
            $options .= '<option value="' . esc_attr((string) $id) . '"' . selected($id, $project, false) . '>' . esc_html($label) . '</option>';
        }

        $body = '<label class="taxmod-dialog-field" style="display:block;margin-bottom:.5em">' . esc_html__('Projekt', 'taxmod-manufacturing') . '<br><select name="project" required>' . $options . '</select></label>'
            . '<label class="taxmod-dialog-field" style="display:block">' . esc_html__('Stückzahl', 'taxmod-manufacturing') . '<br><input type="number" name="count" min="1" step="1" value="1" required></label>';

        if (method_exists(ListPage::class, 'shownBy')) {
            // D-1311: the boards of the chosen project show in the dialog itself; the server still takes only that project's.
            foreach (array_intersect_key($this->boards, $projects) as $project => $boards) {
                $body .= ListPage::shownBy('project', (string) $project, '<fieldset><legend>' . esc_html__('Platinen', 'taxmod-manufacturing') . '</legend>' . $this->boardBoxes($boards) . '</fieldset>');
            }

            $body .= $this->hidden(['choosing' => '1']);
        } else {
            $body .= '<p>' . esc_html__('Im nächsten Schritt wählst du, welche Platinen des Projekts gebaut werden.', 'taxmod-manufacturing') . '</p>';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'tab' => $this->tab()] + ($box > 0 ? ['box' => (string) $box] : []))
            . ListPage::dialogAction('taxmod-mf-new' . ($box > 0 ? '-' . $box : ''), $box > 0 ? __('+ Auftrag', 'taxmod-manufacturing') : __('Neuer Auftrag', 'taxmod-manufacturing'), __('Neuer Fertigungsauftrag', 'taxmod-manufacturing'), $body, 'do', 'create', __('Anlegen', 'taxmod-manufacturing'))
            . '</form>';
    }

    /** Second step of «Neuer Auftrag»: which boards of the chosen project are built, each in its newest revision (D-1300). */
    private function chooseBoards(): string
    {
        $project = absint($_GET['neu'] ?? 0);

        if ($project === 0) {
            return '';
        }

        $boards = $this->service->boardsOf($project);

        if ($boards === []) {
            return '<div class="notice notice-error"><p>' . esc_html($this->message(ManufacturingError::projectWithoutBoards($project))) . '</p></div>';
        }

        $boxes = $this->boardBoxes($boards);
        $name = $this->service->labelsOf([$project])[$project] ?? '#' . $project;

        return '<div class="notice notice-info"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'tab' => $this->tab(), 'do' => 'create', 'choosing' => '1', 'project' => (string) $project, 'count' => sanitize_text_field(wp_unslash((string) ($_GET['count'] ?? '1')))])
            . '<p><strong>' . esc_html(sprintf(__('Welche Platinen von «%s» sollen gebaut werden?', 'taxmod-manufacturing'), $name)) . '</strong></p>'
            . ListPage::toolbar([
                '<button type="submit" class="button button-primary">' . esc_html__('Anlegen', 'taxmod-manufacturing') . '</button>',
                '<a class="button" href="' . esc_url($this->url(['tab' => $this->tab()])) . '">' . esc_html__('Abbrechen', 'taxmod-manufacturing') . '</a>',
            ])
            . $boxes
            . '</form></div>';
    }

    /** D-1308: «Speichern» with the diskette, first in the toolbar above its form. */
    private function saveButton(): string
    {
        return ControlMarkup::button(Control::saving('submit', '1', __('Speichern', 'taxmod-manufacturing'), __('Speichern', 'taxmod-manufacturing')));
    }

    /** @param array<int, string> $boards newest revision id => label; a single board comes ticked */
    private function boardBoxes(array $boards): string
    {
        $only  = count($boards) === 1;
        $boxes = '';

        foreach ($boards as $revision => $label) {
            $boxes .= '<p><label><input type="checkbox" name="boards[]" value="' . esc_attr((string) $revision) . '"' . ($only ? ' checked' : '') . '> ' . esc_html($label) . '</label></p>';
        }

        return $boxes;
    }

    private function dialogForm(string $do, BuildOrder $order, string $opener, string $title, string $body): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'order' => (string) $order->id, 'tab' => $this->tab(), 'view' => absint($_GET['auftrag'] ?? 0) === $order->id ? 'detail' : ''])
            . ListPage::dialogAction('taxmod-mf-' . $do . '-' . $order->id, $opener, $title, '<p>' . $body . '</p>', 'do', $do, $opener)
            . '</form>';
    }

    /** @param array<string, string> $fields */
    private function hidden(array $fields): string
    {
        $html = '';

        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }

        return $html;
    }

    private function heading(): string
    {
        return ListPage::heading(__('Aufträge', 'taxmod-manufacturing'), (string) TAXMOD_MANUFACTURING_VERSION, __('Fertigung', 'taxmod-manufacturing'));
    }

    private function notice(): string
    {
        $error = sanitize_text_field(rawurldecode(wp_unslash((string) ($_GET['err'] ?? ''))));

        if ($error !== '') {
            return '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        $text = [
            'created'   => __('Auftrag angelegt.', 'taxmod-manufacturing'),
            'saved'     => __('Platine gespeichert.', 'taxmod-manufacturing'),
            'saved_booked'   => __('Platine gespeichert, Verbrauch im Lager gebucht.', 'taxmod-manufacturing'),
            'saved_unbooked' => __('Platine gespeichert. Der Verbrauch ist noch nicht gebucht: das Lager bietet die Buchung noch nicht an.', 'taxmod-manufacturing'),
            'box'       => __('Gebinde gespeichert.', 'taxmod-manufacturing'),
            'reordered' => __('Fehlendes als Bedarf im Einkauf angelegt.', 'taxmod-manufacturing'),
            'nothing_missing' => __('Es fehlt nichts, was nirgends am Lager liegt.', 'taxmod-manufacturing'),
            'picked'    => __('Abgehakte Teile ins Gebinde umgebucht.', 'taxmod-manufacturing'),
            'nothing_picked' => __('Nichts abgehakt, nichts gebucht.', 'taxmod-manufacturing'),
            'closed'    => __('Auftrag abgeschlossen. «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'),
            'cancelled' => __('Abgebrochen. «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'),
            'reopened'  => __('Wieder aufgenommen.', 'taxmod-manufacturing'),
            'staging'   => __('In Bereitstellung.', 'taxmod-manufacturing'),
            'reserved'  => __('Abgehakte Teile für den Auftrag reserviert.', 'taxmod-manufacturing'),
            'cleared'   => __('Gebinde leer geräumt, alles zurück an seinen Platz.', 'taxmod-manufacturing'),
            'building'  => __('In Fertigung.', 'taxmod-manufacturing'),
        ][sanitize_key((string) ($_GET['msg'] ?? ''))] ?? '';

        return $text === '' ? '' : '<div class="notice notice-success"><p>' . esc_html($text) . '</p></div>';
    }

    private function message(ManufacturingError $error): string
    {
        return match ($error->reason) {
            'not_configured'           => sprintf(__('Im Modell fehlt %s.', 'taxmod-manufacturing'), $error->detail),
            'shortage_without_reason'  => __('Es fehlen noch Teile: bitte einen Grund angeben.', 'taxmod-manufacturing'),
            'no_board_chosen'          => __('Keine Platine gewählt.', 'taxmod-manufacturing'),
            'project_without_boards'   => __('Das Projekt hat keine Platine mit Revision.', 'taxmod-manufacturing'),
            'count_not_positive'       => __('Die Stückzahl muss eine ganze Zahl ab 1 sein.', 'taxmod-manufacturing'),
            'unknown_order'            => __('Diesen Auftrag gibt es nicht.', 'taxmod-manufacturing'),
            'assembly_not_of_order'    => __('Diese Platine gehört nicht zum Auftrag.', 'taxmod-manufacturing'),
            'boards_out_of_range'      => __('Eine Platinenzahl liegt außerhalb von 0 bis zur Stückzahl.', 'taxmod-manufacturing'),
            'tested_beyond_fitted'     => __('Geprüft können höchstens so viele Platinen sein, wie voll bestückt sind.', 'taxmod-manufacturing'),
            'done_beyond_tested'       => __('Fertig ist eine Platine erst nach der Prüfung: «Fertig» höchstens so viele wie «Geprüft».', 'taxmod-manufacturing'),
            'sparte_needed'            => __('Für die erste fertige Platine dieser Revision bitte die Hardware-Sparte wählen.', 'taxmod-manufacturing'),
            'stock_refused'            => sprintf(__('Das Lager hat die Buchung abgelehnt, nichts gespeichert: %s', 'taxmod-manufacturing'), $error->detail),
            'assemblies_not_done'      => __('Erst wenn alle Platinen fertig sind, lässt sich der Auftrag abschließen.', 'taxmod-manufacturing'),
            'position_not_on_revision' => __('Nur optionale Positionen der Stückliste lassen sich weglassen.', 'taxmod-manufacturing'),
            'model_refused'            => sprintf(__('Das Modell hat abgelehnt: %s', 'taxmod-manufacturing'), $error->detail),
            default                    => __('Unbekannte Aktion.', 'taxmod-manufacturing'),
        };
    }

    /** The step, and «Fehlteile» while an order that went into building with parts missing still lacks some (D-1301). */
    private function stepCell(BuildOrder $order, int $missing): string
    {
        $html = esc_html($this->orderStepLabel($order->step));

        if ($order->step === 'order_building' && $order->shortageReason !== '' && $missing > 0) {
            $html .= ' <strong title="' . esc_attr($order->shortageReason) . '">' . esc_html__('Fehlteile', 'taxmod-manufacturing') . '</strong>';
        }

        return $html;
    }

    private function orderStepLabel(string $step): string
    {
        return [
            'order_planned'   => __('geplant', 'taxmod-manufacturing'),
            'order_staging'   => __('Bereitstellung', 'taxmod-manufacturing'),
            'order_building'  => __('in Bau', 'taxmod-manufacturing'),
            'order_done'      => __('fertig', 'taxmod-manufacturing'),
            'order_cancelled' => __('abgebrochen', 'taxmod-manufacturing'),
        ][$step] ?? $step;
    }

    private function tab(): string
    {
        $slug = sanitize_key((string) ($_GET['module'] ?? ''));
        $tab  = sanitize_key((string) ($_REQUEST['tab'] ?? (array_search($slug, self::VIEWS, true) ?: 'boxes')));

        return in_array($tab, self::TABS, true) ? $tab : 'boxes';
    }

    private function inTab(BuildOrder $order, string $tab): bool
    {
        return match ($tab) {
            'done', 'order_done' => $order->step === 'order_done',
            'cancelled'          => $order->step === 'order_cancelled',
            'order_planned', 'order_staging', 'order_building' => $order->step === $tab,
            'all'                => true,
            default     => ! $order->isClosed(),
        };
    }

    /** A refusal (capability, nonce) as a message on the page instead of a bare error page (D-1254). */
    private function refuse(string $message): never
    {
        if (method_exists(ListPage::class, 'fail')) {
            ListPage::fail($message, $this->url(['tab' => sanitize_key((string) ($_POST['tab'] ?? ''))]));
        }

        wp_die(esc_html($message), '', ['response' => 403, 'back_link' => true]);
    }

    /** @param array<string, string|int> $args */
    private function url(array $args): string
    {
        $view = (string) ($args['tab'] ?? '');

        if (isset(self::VIEWS[$view])) {
            $args['module'] = self::VIEWS[$view];
            unset($args['tab']);
        }

        return add_query_arg(array_filter(['page' => 'taxmod-lager-modul', 'module' => self::SLUG] + $args, static fn ($v): bool => $v !== '' && $v !== 0), admin_url('admin.php'));
    }
}
