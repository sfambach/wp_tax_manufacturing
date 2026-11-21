<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\Core\Renderer\Control;
use Taxmod\WordPress\Draw\ControlMarkup;
use Taxmod\WordPress\Draw\GridMarkup;
use Taxmod\WordPress\Draw\SelectMarkup;
use Taxmod\Core\Description\InputKind;
use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Demand;
use TaxmodManufacturing\Core\Staging;
use TaxmodManufacturing\Core\Stopwatch;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\Positions;
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
        'order_test'     => self::SLUG . '-test',
        'order_done'     => self::SLUG . '-fertiggestellt',
        'order_failed'   => self::SLUG . '-fehlgeschlagen',
        'cancelled'      => self::SLUG . '-abgebrochen',
    ];

    /** @return array<string, string> view → its name, the same for the menu point and the arrow */
    public static function viewLabels(): array
    {
        return [
            'boxes'          => __('Projektgebinde', 'taxmod-manufacturing'),
            'order_planned'  => __('In Planung', 'taxmod-manufacturing'),
            'order_staging'  => __('In Bereitstellung', 'taxmod-manufacturing'),
            'order_building' => __('In Bau', 'taxmod-manufacturing'),
            'order_test'     => __('In Test', 'taxmod-manufacturing'),
            // Prüftor 20.11. (3b): the orders with a board in «Nacharbeit» — a view stacked under «In Test», no status, no menu point.
            'order_rework'   => __('Nacharbeit', 'taxmod-manufacturing'),
            'order_done'     => __('Fertig', 'taxmod-manufacturing'),
            'order_failed'   => __('Fehlgeschlagen', 'taxmod-manufacturing'),
            'cancelled'      => __('Abgebrochen', 'taxmod-manufacturing'),
        ];
    }

    /** Every view the list knows; «open», «done» and «all» stay for old links. */
    public const TABS = ['boxes', 'open', 'done', 'cancelled', 'all', 'order_planned', 'order_staging', 'order_building', 'order_test', 'order_rework', 'order_done', 'order_failed'];

    public const ACTION = 'taxmod_manufacturing';

    public const BULK_CANCEL = 'taxmod_manufacturing_cancel';

    public const BULK_REOPEN = 'taxmod_manufacturing_reopen';

    /** Stefan 21.11. 10:30 «zurück in Planung und vor in Bau, dass das klar ist»: the steps as bulk choices. */
    public const BULK_STAGING = 'taxmod_manufacturing_to_staging';

    public const BULK_BUILDING = 'taxmod_manufacturing_to_building';

    public const BULK_BACK = 'taxmod_manufacturing_back';

    public const STEPPING = [self::BULK_STAGING, self::BULK_BUILDING, self::BULK_BACK];

    /** @var list<string> the admin-post actions this page answers */
    /** D-1403: the pick list's choices of its one bulk selection. */
    public const BULK_PICK = StagingSection::PICK;

    public const BULK_RESERVE = StagingSection::RESERVE;

    /** D-1403: the fitting table's choices of its one bulk selection (his word 17.11. «keine kästchen am anfang für bulk operation»). */
    public const BULK_FIT_FULL = 'taxmod_manufacturing_fit_full';

    public const BULK_FIT_NONE = 'taxmod_manufacturing_fit_none';

    public const BULK_UNUSE = 'taxmod_manufacturing_unuse';

    public const BULK_USE = 'taxmod_manufacturing_use';

    public const FITTING = [self::BULK_FIT_FULL, self::BULK_FIT_NONE, self::BULK_UNUSE, self::BULK_USE];

    public const ACTIONS = [self::ACTION, self::BULK_CANCEL, self::BULK_REOPEN, ...self::STEPPING, StagingSection::PICK, StagingSection::RESERVE, StagingSection::CHANGE, StagingSection::RELEASE, ...self::FITTING, ...TestSection::ACTIONS];

    public const NONCE = 'taxmod_manufacturing';

    private const FORM = 'taxmod-mf-bulk';

    /** @var array<int, string>|null read once per page, however many «+ Auftrag» dialogs it draws (CD-7) */
    private ?array $projects = null;

    /** @var array<int, array<int, string>>|null */
    private ?array $boards = null;
    /** @var array<string, string> Review F5: order:assembly → its category chooser, drawn once per page */
    private array $choosers = [];

    public function __construct(private readonly ManufacturingService $service = new ManufacturingService())
    {
    }

    public function render(): string
    {
        if (! class_exists(ListPage::class)) {
            return '<div class="notice notice-error"><p>' . esc_html__('Der Taxonomy Modeller ist zu alt: die gemeinsame Listenansicht fehlt.', 'taxmod-manufacturing') . '</p></div>';
        }

        $base    = dirname(__DIR__, 3) . '/wp_tax_manufacturing.php';
        $version = (string) TAXMOD_MANUFACTURING_VERSION;
        wp_enqueue_style('taxmod-mf-page', plugins_url('assets/manufacturing.css', $base), [], $version);
        wp_enqueue_script('taxmod-mf-page', plugins_url('assets/manufacturing.js', $base), [], $version, true);

        // D-1373, D-1186: the page stands in the modeller's template, not in a frame of its own.
        return ListPage::page(__('Aufträge', 'taxmod-manufacturing'), [], [['html' => $this->content()]], (string) TAXMOD_MANUFACTURING_VERSION, __('Fertigung', 'taxmod-manufacturing'));
    }

    private function content(): string
    {
        $html = $this->notice();

        $missing = $this->service->structure()->missing();

        if ($missing !== []) {
            return $html . '<div class="notice notice-warning"><p>' . esc_html__('Das Modell der Fertigung ist noch nicht eingespielt. Es fehlen:', 'taxmod-manufacturing') . ' <code>' . esc_html(implode(', ', $missing)) . '</code></p></div>';
        }

        $asked = sanitize_key((string) ($_GET['tab'] ?? ''));

        if ($asked !== '' && ! in_array($asked, self::TABS, true)) {
            return $html . '<div class="notice notice-error"><p>' . esc_html__('Diese Ansicht gibt es nicht.', 'taxmod-manufacturing')
                . ' <a href="' . esc_url($this->url(['tab' => 'boxes'])) . '">' . esc_html__('Zu den Projektgebinden', 'taxmod-manufacturing') . '</a></p></div>';
        }

        try {
            $orders = $this->service->orders();
        } catch (ManufacturingError $error) {
            return $html . '<div class="notice notice-error"><p>' . esc_html($this->message($error)) . '</p></div>';
        }

        $detail = absint($_GET['auftrag'] ?? 0);

        if ($detail !== 0) {
            return $html . $this->detail($orders, $detail);
        }

        // D-1433 A: the steps from «In Fertigung» on are what the boards say, read for all orders at once.
        $orders = $this->service->derived($orders);

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
            $this->service->structure()->hasValue('order_test')
                ? [['order_test', $name['order_test'], $in('order_test')], ['order_rework', $name['order_rework'], count(array_filter($orders, fn (BuildOrder $o): bool => $this->inTab($o, 'order_rework')))]]
                : null,
        ]));
        $ends   = array_values(array_filter([['order_done', $name['order_done'], $in('order_done')],
            $this->service->canFail() ? ['order_failed', $name['order_failed'], $in('order_failed')] : null, ['cancelled', $name['cancelled'], $in('order_cancelled')]]));
        // D-1346: the two ends side by side came with the modeller 0.2.86; before it «Abgebrochen» stands beside as a tab.
        $stacked = version_compare(\Taxmod\WordPress\Plugin::VERSION, '0.2.86', '>=');
        $steps[] = $stacked ? $ends : $ends[0];
        $beside  = $stacked ? [] : array_slice($ends, 1);
        $shown = array_values(array_filter($orders, fn (BuildOrder $o): bool => $this->inTab($o, $tab)));

        // D-1526 (his word 20.11. 18:21 «eine Toolbar»): the bar, then the one row — bulk actions, «Neuer Auftrag», the count.
        $html .= $this->chooseBoards()
            . ListPage::steps($steps, $tab, fn (string $slug): string => $this->url(['tab' => $slug]), $beside, ['all', __('Alle', 'taxmod-manufacturing'), count($orders)])
            . ListPage::bulkBar(self::FORM, self::NONCE, $this->bulkChoices($tab),
                sprintf(_n('%d Auftrag', '%d Aufträge', count($shown), 'taxmod-manufacturing'), count($shown)), '<div class="alignleft actions taxmod-list-buttons">' . $this->createAction() . '</div>');

        if ($tab === 'boxes') {
            return $html . $this->boxList($orders, $boxes);
        }

        // The orders sharing a box with a shown one count too: the box serves them in turn (his word 16.11., Atari).
        $boxes            = array_flip(array_filter(array_map(static fn (BuildOrder $o): int => (int) $o->box, $shown)));
        $related          = array_values(array_filter($orders, static fn (BuildOrder $o): bool => in_array($o, $shown, true) || (! $o->isClosed() && isset($boxes[(int) $o->box]))));
        [$lists, $labels] = $this->context($related);
        $ids              = array_map(static fn (BuildOrder $o): int => $o->id, $related);
        $reserved         = $this->service->reservedFor($ids, array_combine($ids, array_map(static fn (BuildOrder $o): ?int => $o->box, $related)));
        // His word 2026-11-15 «warum braucht er so lange … angelegt»: the boxes' contents in one read, not the ledger per order.
        $present          = $this->service->presentInEach(array_values(array_keys($boxes)));
        $assigned         = $this->service->assignments($ids);
        $own              = [];

        foreach ($related as $order) {
            $own[$order->id] = $this->service->assignedLists($order, $lists, $assigned[$order->id] ?? []);
        }

        $shares = Demand::boxShares($related, $own, $reserved, $present);
        $rows   = '';
        // His report 18.11. «3 Atari Card Reader … nicht ersichtlich, welcher es denn ist»: the board each order builds, read once.
        $made   = $this->boardNames(array_values(array_unique(array_merge([], ...array_map(static fn (BuildOrder $o): array => array_column($o->assemblies, 'revision'), $shown)))), $labels);

        foreach ($shown as $order) {
            $rows .= $this->row($order, $labels, $made, $own[$order->id], Demand::of($order->assemblies, $own[$order->id], $order->count, $this->service->available($order, $reserved[$order->id] ?? [], $shares[$order->id] ?? [])));
        }

        if ($rows === '') {
            $rows = '<tr class="no-items"><td class="colspanchange" colspan="11">' . esc_html__('Keine Aufträge.', 'taxmod-manufacturing') . '</td></tr>';
        }

        return $html . ListPage::table([
            [__('Auftrag', 'taxmod-manufacturing')],
            [__('Projekt', 'taxmod-manufacturing')],
            [__('Bauplan', 'taxmod-manufacturing')],
            [__('Stück', 'taxmod-manufacturing'), '5em'],
            [__('Status', 'taxmod-manufacturing'), '8em'],
            [__('Bestückt/Fertig', 'taxmod-manufacturing'), '9em'],
            [__('Gebinde', 'taxmod-manufacturing')],
            [__('Fehlende Pos.', 'taxmod-manufacturing'), '8em'],
            [__('Begonnen', 'taxmod-manufacturing'), '8em'],
        ], $rows);
    }

    /**
     * One order on its own page (D-1376): the status on top, the step back and forward, the order's fields — count and revision
     * while it is «Angelegt» — and below them what belongs to its status: the pick list, the fitting.
     *
     * @param list<BuildOrder> $orders
     */
    private function detail(array $orders, int $id): string
    {
        $found = array_values(array_filter($orders, static fn (BuildOrder $o): bool => $o->id === $id));
        $list  = $this->url(['tab' => $this->tab()]);

        if ($found === []) {
            return '<div class="notice notice-error"><p>' . esc_html__('Diesen Auftrag gibt es nicht.', 'taxmod-manufacturing')
                . ' <a href="' . esc_url($list) . '">' . esc_html__('Zur Liste', 'taxmod-manufacturing') . '</a></p></div>';
        }

        // D-1433: the boards of the order, read once — their statuses, the order's step derived from them, the positions' list.
        $lots    = [];

        if (in_array($found[0]->step, ['order_building', 'order_test', 'order_done', 'order_failed'], true) && $this->service->canTest()) {
            try {
                $lots = $this->service->testLots($found[0]->id);
            } catch (ManufacturingError) {
                $lots = [];
            }
        }

        $order     = Positions::derive($found[0], $lots);
        $counts    = Positions::counts($order, $lots);
        [$plain, $labels] = $this->context([$order]);
        $assigned = $this->service->assignments([$order->id])[$order->id] ?? [];
        $lists    = $this->service->assignedLists($order, $plain, $assigned);
        $share   = $this->boxShare($orders, $order) ?? $this->service->presentIn($order->box);
        $held    = $this->service->reservationRows($order->id);
        // Spec. §3: what counts as there is the box and what is reserved outside it; a reserved lot in the box counts once.
        $demand  = Demand::of($order->assemblies, $lists, $order->count, Staging::available($share, $held, $order->box));
        $missing = Demand::missingRows($demand);
        $name    = self::viewLabels();
        $staging = $this->service->structure()->hasValue('order_staging');
        $fitted  = array_filter($order->assemblies, static fn (Assembly $a): bool => array_filter(array_column($a->fitted, 'boards')) !== []) !== [];
        $started = in_array($order->step, ['order_building', 'order_test', 'order_done'], true) || ($order->step === 'order_cancelled' && $order->started !== null);
        $form    = 'taxmod-mf-step-' . $order->id;
        $steps   = array_values(array_filter([
            ['order_planned', $name['order_planned']],
            $staging ? ['order_staging', $name['order_staging']] : null,
            ['order_building', $name['order_building']],
            $this->service->structure()->hasValue('order_test') && ! $order->kit ? [['order_test', $name['order_test']], ['order_rework', $name['order_rework']]] : null,
            array_values(array_filter([['order_done', $name['order_done']], $this->service->canFail() ? ['order_failed', $name['order_failed']] : null, ['order_cancelled', $name['cancelled']]])),
        ]));

        // D-1376: the step back while nothing is fitted; else the left place leads to the list.
        $previous = match (true) {
            $order->step === 'order_staging'                => 'order_planned',
            $order->step === 'order_building' && ! $fitted => $staging ? 'order_staging' : 'order_planned',
            default                                         => '',
        };
        // His word 16.11. «wenn was reserviert ist kann ich das nicht mehr ändern, selbst wenn ich es zurück in angelegt schiebe»:
        // back to «Angelegt» gives the order's reservations free (D-1384: picking belongs to «Bereitstellung»); the box keeps its parts.
        $back = match (true) {
            // D-1433 D, architecture 18.11.: the finished boards go back onto «Test», untested; before the completion only the undo.
            $counts[Positions::DONE] > 0 && in_array($order->step, ['order_test', 'order_done'], true) => $this->dialogForm('back_test', $order, '← ' . __('Zurück in Bau', 'taxmod-manufacturing'), __('Zurück in Bau', 'taxmod-manufacturing'),
                esc_html(sprintf(_n('%d fertiges Stück geht zurück auf «Test», ungetestet. Ein Vorgang, zurücknehmen über die Änderungsnummer.', '%d fertige Stück gehen zurück auf «Test», ungetestet. Ein Vorgang, zurücknehmen über die Änderungsnummer.', $counts[Positions::DONE], 'taxmod-manufacturing'), $counts[Positions::DONE]))),
            // His report 18.11.: «Zurück» led to the list, so it says so.
            $previous === ''                                       => ListPage::stepButton(__('Übersicht', 'taxmod-manufacturing'), false, $list),
            $previous === 'order_planned' && $held !== []          => $this->dialogForm('back', $order, '← ' . $name[$previous], sprintf(__('Zurück nach «%s»', 'taxmod-manufacturing'), $name[$previous]),
                esc_html(sprintf(_n('%d Reservierung dieses Auftrags wird freigegeben (Storno im Lager). Was schon im Gebinde liegt, bleibt dort.', '%d Reservierungen dieses Auftrags werden freigegeben (Storno im Lager). Was schon im Gebinde liegt, bleibt dort.', count($held), 'taxmod-manufacturing'), count($held)))),
            default                                                => ListPage::stepButton($name[$previous], false, '', 'do', 'back', $form),
        };

        $forward = match (true) {
            $order->step === 'order_planned' && $staging => ListPage::stepButton($name['order_staging'], true, '', 'do', 'staging', $form),
            in_array($order->step, ['order_planned', 'order_staging'], true) => $missing > 0 && $order->box !== null
                ? $this->dialogForm('building', $order, $name['order_building'] . ' →', __('Trotz Fehlteilen in Bau geben', 'taxmod-manufacturing'),
                    esc_html(sprintf(_n('Es fehlt noch %d Position. Der Auftrag bleibt mit «Fehlteile» markiert, bis alles da ist.', 'Es fehlen noch %d Positionen. Der Auftrag bleibt mit «Fehlteile» markiert, bis alles da ist.', $missing, 'taxmod-manufacturing'), $missing))
                    . '</p><p><label>' . esc_html__('Grund', 'taxmod-manufacturing') . '<br>' . ListPage::field(InputKind::Line, 'reason', '', ['required' => true, 'size' => 40]) . '</label>')
                : ListPage::stepButton($name['order_building'], true, '', 'do', 'building', $form),
            // D-1419: «Fertigung abschließen» for the fully fitted boards; once all are completed the order is in «Test».
            $order->step === 'order_building' && ! $order->isFinished() && $this->service->structure()->hasField('assembly_done') => $this->finishDialog($order, $labels),
            $order->step === 'order_building' && $order->isFinished() && ! $this->service->structure()->hasValue('order_test') => ListPage::stepButton($name['order_done'], true, '', 'do', 'done', $form),
            // D-1433 D: «Fertigstellen» passes every open board and moves it to «Wareneingang Produktion».
            $order->step === 'order_test' => $this->dialogForm('finish_all', $order, __('Fertigstellen', 'taxmod-manufacturing') . ' →', __('Alle offenen Stücke fertigstellen', 'taxmod-manufacturing'),
                esc_html(sprintf(_n('%d offenes Stück gilt als bestanden und geht auf «Wareneingang Produktion». Ein Vorgang, zurücknehmen über die Änderungsnummer.', '%d offene Stück gelten als bestanden und gehen auf «Wareneingang Produktion». Ein Vorgang, zurücknehmen über die Änderungsnummer.', $counts[Positions::TEST] + $counts[Positions::REWORK], 'taxmod-manufacturing'), $counts[Positions::TEST] + $counts[Positions::REWORK]))),
            default => '',
        };

        // Stefan 20.11. 20:39: «der Speichern-Knopf auch in die Leiste» — the master data's 💾 joins its form from the one toolbar.
        $actions = $order->isClosed() || $order->step === 'order_test' ? [] : [$this->saveButton(self::headFormId($order))];

        // His word 17.11. 22:04 «warum ist der button gebinde leer räumen überall in fertigung zu sehen»: what is left goes back
        // once the order has ended, and only when something is left.
        if ($order->box !== null && $order->isClosed() && has_filter(ManufacturingService::RETURN_FILTER) && $this->service->presentIn($order->box) !== []) {
            $actions[] = $this->dialogForm('clear', $order, __('Gebinde leer räumen', 'taxmod-manufacturing'), __('Gebinde leer räumen', 'taxmod-manufacturing'),
                esc_html__('Alles im Gebinde geht zurück an den Platz, von dem es kam. Ein Vorgang, rückgängig über Storno.', 'taxmod-manufacturing'));
        }

        // D-1475: the stopwatch while the parts are staged and the boards built, one icon square in the toolbar.
        if (in_array($order->step, Stopwatch::STEPS, true) && $this->service->canTime()) {
            $actions[] = $this->timerControl($order, $form);
        }

        // D-1464: the plan does not work — only from «Fertig», only for a built order.
        if ($order->step === 'order_done' && ! $order->kit && $this->service->canFail()) {
            $actions[] = $this->failDialog($order);
        }

        $actions[] = $order->isClosed()
            ? $this->dialogForm('reopen', $order, __('Wieder aufnehmen', 'taxmod-manufacturing'), __('Wieder aufnehmen', 'taxmod-manufacturing'),
                esc_html__('Der Auftrag kommt zurück, mit dem Stand seiner Stücke.', 'taxmod-manufacturing'))
            : $this->dialogForm('cancel', $order, __('Abbrechen', 'taxmod-manufacturing'), __('Abbrechen', 'taxmod-manufacturing'),
                esc_html__('Nichts wird gelöscht; «Wieder aufnehmen» holt ihn zurück.', 'taxmod-manufacturing'));

        $sections = [];
        // His word 2026-11-15: taking parts out belongs to «Bereitstellung», not to «Angelegt» (without that stage in the model it stays at «Angelegt»).
        $picking  = $order->step === 'order_staging' || ($order->step === 'order_planned' && ! $staging) || $order->step === 'order_building';
        $picks    = $picking ? (new StagingSection($this->service, self::NONCE, self::ACTION, $this->tab()))->render($order, $demand, $plain, $share, $held, $labels) : '';

        if ($picks !== '') {
            $sections[] = ['title' => __('Material bereitstellen', 'taxmod-manufacturing'), 'html' => $picks, 'open' => ! $started];
        }

        // His word 2026-11-15: at «Angelegt» only the build options are chosen; everything else happens from «Bereitstellung» on.
        [$options, $count] = $order->step === 'order_planned' ? $this->optionsForm($order, $labels, $demand, $plain) : ['', 0];

        // Stefan 20.11. 19:51, Prüftor: «Positionen (n)» as the section's title, one bar under it; no heading per plan.
        if ($options !== '') {
            $sections[] = ['title' => sprintf(__('Positionen (%d)', 'taxmod-manufacturing'), $count), 'html' => $options];
        }

        $leftOut = $order->isClosed() || $order->step === 'order_planned' ? '' : $this->leftOut($order, $labels, $demand);

        if ($leftOut !== '') {
            $sections[] = ['title' => __('Nicht für den Aufbau ausgewählt', 'taxmod-manufacturing'), 'html' => $leftOut, 'open' => ! $started];
        }

        // D-1428: a kit is packed, not fitted, and not tested.
        if ($started && ! $order->kit) {
            $fitting = '';

            foreach ($order->assemblies as $assembly) {
                $fitting .= $this->assemblyForm($order, $assembly, $labels, $lists[$assembly->revision] ?? [], $demand[$assembly->id] ?? []);
            }

            $sections[] = ['title' => __('Bestückung', 'taxmod-manufacturing'), 'html' => $fitting, 'open' => $order->step === 'order_building'];
        }

        // D-1419, D-1433: the boards with their statuses from the first completion on; a kit's too, for its labels.
        if (in_array($order->step, ['order_building', 'order_test', 'order_done', 'order_failed'], true) && $order->doneBoards() > 0) {
            $sections[] = ['title' => __('Positionen', 'taxmod-manufacturing'), 'html' => (new TestSection($this->service, self::NONCE, $this->tab()))->render($order, $labels, $lots, $counts), 'open' => $order->step !== 'order_building'];
        }

        $headed = method_exists(ListPage::class, 'recordHead');

        return '<form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'order' => (string) $order->id, 'tab' => $this->tab(), 'view' => 'detail']) . '</form>'
            // Stefan 20.11. 20:39 «oben wieder Fertigung, Aufträge und da kommt dann der Name hin»: the standard head (D-1526) with
            // the way back; the record's own title stays empty, so the name stands once.
            . ($headed ? ListPage::recordHead(__('Auftrag', 'taxmod-manufacturing'), $order->label, $list, __('Aufträge', 'taxmod-manufacturing')) : '')
            . ListPage::record($headed ? '' : sprintf(__('Fertigungsauftrag · %s', 'taxmod-manufacturing'), $order->label), $steps,
                // «Nacharbeit» lights when every open board waits for it; with boards still in test «In Test» does.
                $order->step === 'order_test' && $counts[Positions::TEST] === 0 && $counts[Positions::REWORK] > 0 ? 'order_rework' : $order->step, $back, $forward, $actions, $this->headForm($order, $labels, $missing, $demand), $sections);
    }

    /**
     * Every position of each board, ticked = built (his words 2026-11-15: «auswählen, welche wir aufbauen wollen», «den Sockel
     * weglassen»). What is not ticked is left out, as «nicht verwenden» at the fitting.
     *
     * @param array<int, string> $labels
     * @param array<int, list<array{position: int, thing: int, optional: bool, unused: bool}>> $demand
     */
    private function optionsForm(BuildOrder $order, array $labels, array $demand, array $plain = []): array
    {
        // Architecture 17.11.: one 💾 on every step — the ticks join the master data's form.
        $formId = self::headFormId($order);
        $boards = $this->boardNames(array_map(static fn (Assembly $a): int => $a->revision, $order->assemblies), $labels);
        $list   = '';
        $count  = 0;

        foreach ($order->assemblies as $assembly) {
            $all   = $demand[$assembly->id] ?? [];
            $rows  = array_filter($all, static fn (array $row): bool => $row['position'] > 0);
            $lines = array_column($plain[$assembly->revision] ?? [], null, 'id');
            // D-1178/D-1398, Stefan 20.11. 19:44 («80 Stück»): a piece cut from a strip says its poles and its designator, and
            // what is missing says «Pins» — the strip's own name alone read as whole strips.
            $miss  = static fn (array $row, int $piece): string => $row['missing'] <= 0.0 ? '' : ($piece > 0
                ? sprintf(__('fehlt %s Pins', 'taxmod-manufacturing'), ListPage::number($row['missing'], 2))
                : sprintf(__('fehlt %s', 'taxmod-manufacturing'), ListPage::number($row['missing'], 2)));
            $a     = (string) $assembly->id;

            // His word 2026-11-15 «die platine wird auch nicht aufgelistet»: the bare board heads the list; it is always built.
            if (isset($all[0]) && $all[0]['position'] === 0) {
                $list .= '<tr><th scope="row" class="check-column"><input type="checkbox" checked disabled></th><td>' . esc_html(sprintf(__('Bauplan: %s', 'taxmod-manufacturing'), $boards[$assembly->revision] ?? '#' . $assembly->revision))
                    . '</td><td>' . esc_html($miss($all[0], 0)) . '</td><td></td></tr>';
                $count++;
            }

            foreach ($rows as $row) {
                $line  = $lines[$row['position']] ?? [];
                $piece = (int) ($line['piece'] ?? 0);
                $ref   = trim((string) ($line['ref'] ?? ''));
                $name  = ($labels[$row['thing']] ?? '#' . $row['thing'])
                    . ($piece > 0 ? ' · ' . sprintf(__('%d-polig', 'taxmod-manufacturing'), $piece) : '')
                    . ($ref !== '' ? ' (' . $ref . ')' : '');
                $list .= '<tr><th scope="row" class="check-column">' . ListPage::field(InputKind::Tick, 'build[' . $a . '][]', (string) $row['position'], ['checked' => (string) (! $row['unused']) === '1', 'form' => $formId])
                    . '<input type="hidden" name="optional[' . esc_attr($a) . '][]" form="' . esc_attr($formId) . '" value="' . esc_attr((string) $row['position']) . '"></th>'
                    . '<td>' . esc_html($name) . '</td><td>' . esc_html($row['unused'] ? '' : $miss($row, $piece)) . '</td><td></td></tr>';
                $count++;
            }
        }

        // Architecture 16.11.: «Alle auswählen / abwählen» is the tick in the table head, not two buttons. One bar above the
        // table (D-1500), into which the list head puts grouping, filter and search.
        return [ListPage::listBar(sprintf(_n('%d Position', '%d Positionen', $count, 'taxmod-manufacturing'), $count)) . ListPage::table([[__('Position', 'taxmod-manufacturing')], [__('Fehlt', 'taxmod-manufacturing')]], $list), $count];
    }

    /**
     * The positions left out at «Angelegt», each with «wieder hinzufügen» while nothing of its board is used yet.
     *
     * @param array<int, string> $labels
     * @param array<int, list<array{position: int, thing: int, unused: bool}>> $demand
     */
    private function leftOut(BuildOrder $order, array $labels, array $demand): string
    {
        $items = '';

        foreach ($order->assemblies as $assembly) {
            $used = array_filter(array_column($assembly->fitted, 'boards')) !== [];

            foreach ($demand[$assembly->id] ?? [] as $row) {
                if (! $row['unused'] || $row['position'] <= 0) {
                    continue;
                }

                $items .= '<li>' . esc_html($labels[$row['thing']] ?? '#' . $row['thing']) . ($used ? '' : ' '
                    . ListPage::iconButton('undo', __('wieder hinzufügen', 'taxmod-manufacturing'), '', 'readd', $assembly->id . ':' . $row['position'], 'taxmod-mf-step-' . $order->id)) . '</li>';
            }
        }

        return $items === '' ? '' : '<ul>' . $items . '</ul>';
    }

    /**
     * D-1376: the order's fields through the form renderer. Count and the board's revision change while it is «Angelegt»
     * (one revision per order for now), the box while it is open; the rest is shown.
     *
     * @param array<int, string> $labels
     * @param array<int, list<array{boards: int, position: int, unused: bool}>> $demand assembly → rows, from Demand::of
     */
    private function headForm(BuildOrder $order, array $labels, int $missing, array $demand = []): string
    {
        $planned  = $order->step === 'order_planned';
        $id       = 'taxmod-mf-head-' . $order->id;
        $revision = count($order->assemblies) === 1 ? $order->assemblies[0]->revision : 0;
        $named    = $this->service->labelsOf(array_column($order->assemblies, 'revision'));
        $boards   = $this->service->boardsOfRevisions(array_column($order->assemblies, 'revision'));
        $boardOf  = $this->service->labelsOf(array_values($boards));
        $board    = static fn (int $rev): string => trim(($boardOf[$boards[$rev] ?? 0] ?? '') . ' · ' . ($named[$rev] ?? '#' . $rev), ' ·');
        $text     = static fn (string $value): string => '<strong>' . esc_html($value) . '</strong>';
        // His question 18.11. «warum kann ich Stück und Gebinde im Test noch ändern»: from «Test» on the boards are built.
        $locked   = $order->isClosed() || $order->step === 'order_test';

        if ($planned && $revision > 0) {
            $options = [];

            foreach ($this->service->siblingRevisions($revision) as $rev => $label) {
                $options[$rev] = ($boardOf[$boards[$revision] ?? 0] ?? '') . ' · ' . $label;
            }

            $boardControl = SelectMarkup::of('revision', $options, (string) $revision, false, '', ['id' => $id . '-revision']);
        } else {
            $boardControl = $text(implode(', ', array_map(static fn (Assembly $a): string => $board($a->revision), $order->assemblies)));
        }

        $boxes = [0 => __('— kein Gebinde —', 'taxmod-manufacturing')];

        foreach ($this->service->boxesOf($order->project) as $box => $label) {
            $boxes[$box] = $label;
        }

        $statuses = $this->service->revisionStatuses(array_column($order->assemblies, 'revision'));
        $rows     = [
            ['label' => __('Projekt', 'taxmod-manufacturing'), 'control' => $text($labels[$order->project] ?? '#' . $order->project)],
            ...($this->service->canKit() ? [['label' => __('Art', 'taxmod-manufacturing'), 'control' => $text($order->kit ? __('Kit', 'taxmod-manufacturing') : __('Bestücken', 'taxmod-manufacturing'))]] : []),
            ['label' => _n('Bauplan', 'Baupläne', count($order->assemblies), 'taxmod-manufacturing'), 'control' => $boardControl, 'for' => $planned && $revision > 0 ? $id . '-revision' : '',
                // D-1463: the revision's status as its first order says it, read here, kept nowhere.
                'hint' => implode(' · ', array_map(static fn (int $rev, array $status): string => sprintf(__('Status der Revision %1$s: %2$s', 'taxmod-manufacturing'), $named[$rev] ?? '#' . $rev, $status['label']),
                    array_keys($statuses), $statuses))],
            ['label' => __('Stück', 'taxmod-manufacturing'), 'for' => $locked ? '' : $id . '-count', 'control' => $locked
                ? $text((string) $order->count)
                : ListPage::field(InputKind::Number, 'count', (string) $order->count, ['id' => $id . '-count', 'min' => max([1, ...array_map(static fn ($a): int => $a->done, $order->assemblies)]), 'step' => 1]),
                'hint' => $order->step === 'order_planned' ? '' : __('Weniger Stück geben überzählige Reservierungen frei; was schon im Gebinde liegt, bleibt dort bis «Box leer räumen».', 'taxmod-manufacturing')],
            ['label' => __('Gebinde', 'taxmod-manufacturing'), 'for' => $locked ? '' : $id . '-box', 'control' => $locked
                ? $text($order->box === null ? '—' : ($this->service->labelsOf([$order->box])[$order->box] ?? '#' . $order->box))
                : SelectMarkup::of('box', $boxes, (string) (int) $order->box, false, '', ['id' => $id . '-box']),
                'hint' => __('Was im Gebinde und darunter liegt, zählt als vorhanden. Projekt-Gebinde legst du im Lager an.', 'taxmod-manufacturing')],
            // Stefan 21.11. 10:34: what is missing matters from «Bereitstellung» on, not while planning.
            ...($planned ? [] : [['label' => __('Fehlende Pos.', 'taxmod-manufacturing'), 'control' => $text($order->box === null ? '—' : (string) $missing)]]),
        ];

        if ($this->service->structure()->hasField('order_due')) {
            $rows[] = ['label' => __('Wunschtermin', 'taxmod-manufacturing'), 'for' => $locked ? '' : $id . '-due', 'control' => $locked
                ? $text($order->due ?? '—')
                : ListPage::field(InputKind::Moment, 'due', (string) $order->due, ['id' => $id . '-due', 'note' => 'date']),
                'hint' => __('Optional. Plant nichts, steht nur am Auftrag.', 'taxmod-manufacturing')];
        }

        $rows = [...$rows,
            ['label' => __('Begonnen', 'taxmod-manufacturing'), 'control' => $text($order->started ?? '—')],
            ['label' => __('Fertig am', 'taxmod-manufacturing'), 'control' => $text($order->finished ?? '—')],
        ];

        if ($order->shortageReason !== '') {
            $rows[] = ['label' => __('Grund Fehlteile', 'taxmod-manufacturing'), 'control' => $text($order->shortageReason)];
        }

        // D-1419 and his report 17.11. «button chaos»: the fitting is saved with the master data, by this one 💾; per board its
        // count «Fertig» (shown, not entered), its note and the reason for scrap stand here.
        foreach (in_array($order->step, ['order_building', 'order_test', 'order_done'], true) ? $order->assemblies : [] as $assembly) {
            $a     = (string) $assembly->id;
            $name  = count($order->assemblies) > 1 ? ' · ' . ($named[$assembly->revision] ?? '#' . $assembly->revision) : '';
            $rows[] = $order->kit
                ? ['label' => __('Fertig', 'taxmod-manufacturing') . $name, 'control' => $text(sprintf(__('%1$d von %2$d Kits abgeschlossen', 'taxmod-manufacturing'), $assembly->done, $order->count)),
                    'hint' => __('Gepackte Kits schließt oben «Kits abschließen» ab: Verbrauch von Platine und Teilen buchen, Kits ins Lager.', 'taxmod-manufacturing')]
                : ['label' => __('Fertig', 'taxmod-manufacturing') . $name, 'control' => $text(sprintf(__('%1$d voll bestückt · %2$d von %3$d abgeschlossen', 'taxmod-manufacturing'), Demand::boardsFitted($demand[$assembly->id] ?? []), $assembly->done, $order->count)),
                    'hint' => __('Voll bestückte Stücke schließt oben «Fertigung abschließen» ab: Verbrauch buchen, Stücke ungeprüft auf «Test».', 'taxmod-manufacturing')];

            // His question 18.11. «was ist Hinweis, Grund Ausschuss»: both belong to the fitting, so only to «In Fertigung».
            if ($order->step !== 'order_building') {
                continue;
            }

            $category = $order->step === 'order_building' ? $this->categoryChooser($order, $assembly) : '';

            if ($category !== '') {
                $rows[] = ['label' => __('Material-Kategorie', 'taxmod-manufacturing') . $name, 'control' => $category,
                    'hint' => __('Wo das fertige Stück im Katalog angelegt wird. Gilt beim ersten «Fertigung abschließen».', 'taxmod-manufacturing')];
            }

            if ($this->service->structure()->hasField('assembly_note')) {
                $rows[] = ['label' => __('Hinweis', 'taxmod-manufacturing') . $name, 'for' => $id . '-note-' . $a, 'control' => ListPage::field(InputKind::Line, 'note[' . $a . ']', $assembly->note, ['id' => $id . '-note-' . $a, 'size' => 40])];
            }

            if ($order->box !== null && $this->service->canScrap()) {
                $rows[] = ['label' => __('Grund Ausschuss', 'taxmod-manufacturing') . $name, 'for' => $id . '-scrap-' . $a, 'control' => ListPage::field(InputKind::Line, 'scrap_reason[' . $a . ']', '', ['id' => $id . '-scrap-' . $a, 'list' => 'taxmod-mf-scrap-reasons']),
                    'hint' => __('Optional. Gilt für die Zahlen in der Spalte «Ausschuss» der Bestückung.', 'taxmod-manufacturing')];
            }
        }

        return '<form method="post" id="' . esc_attr(self::headFormId($order)) . '" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'do' => 'head', 'order' => (string) $order->id, 'tab' => $this->tab()])
            . ListPage::form($rows)
            // Once, whatever the number of boards: an id stands once on a page.
            . '<datalist id="taxmod-mf-scrap-reasons"><option value="' . esc_attr__('heruntergefallen', 'taxmod-manufacturing') . '"><option value="' . esc_attr__('defekt', 'taxmod-manufacturing') . '"><option value="' . esc_attr__('falsch bestückt', 'taxmod-manufacturing') . '"></datalist>'
            . '</form>';
    }

    private static function headFormId(BuildOrder $order): string
    {
        return 'taxmod-mf-head-form-' . $order->id;
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
     * What of its box is left for this order once the older open orders sharing the box took theirs (his word 16.11., Atari).
     *
     * @param list<BuildOrder> $orders
     * @return array<int, float>|null thing → pieces; null when no other open order shares the box
     */
    public function boxShare(array $orders, BuildOrder $order): ?array
    {
        $sharing = array_values(array_filter($orders, static fn (BuildOrder $o): bool => $order->box !== null && $o->box === $order->box && ! $o->isClosed() && $o->id < $order->id));

        if ($sharing === []) {
            return null;
        }

        $sharing[]  = $order;
        [$lists]    = $this->context($sharing);
        $ids        = array_map(static fn (BuildOrder $o): int => $o->id, $sharing);
        $assigned   = $this->service->assignments($ids);
        $own        = [];

        foreach ($sharing as $o) {
            $own[$o->id] = $this->service->assignedLists($o, $lists, $assigned[$o->id] ?? []);
        }

        return Demand::boxShares($sharing, $own, $this->service->reservedFor($ids, array_combine($ids, array_map(static fn (BuildOrder $o): ?int => $o->box, $sharing))), [(int) $order->box => $this->service->presentIn($order->box)])[$order->id] ?? [];
    }

    /**
     * Parts lists and labels for the given orders, read once (CD-7).
     *
     * @param list<BuildOrder> $orders
     * @return array{0: array<int, list<array{id: int, thing: int, count: float, fitting: string, ref: string}>>, 1: array<int, string>}
     */
    public function context(array $orders): array
    {
        $revisions = [];

        foreach ($orders as $order) {
            foreach ($order->assemblies as $assembly) {
                $revisions[$assembly->revision] = true;
            }
        }

        $lists   = $this->service->partsLists(array_keys($revisions));
        $things  = [];

        foreach ($lists as $positions) {
            foreach ($positions as $position) {
                $things[] = $position['thing'];
            }
        }

        $labels = $this->service->labelsOf(array_values(array_filter(array_unique([...array_keys($revisions), ...$things, ...array_map(static fn (BuildOrder $o): int => $o->project, $orders), ...array_filter(array_map(static fn (BuildOrder $o): ?int => $o->box, $orders))]))));

        return [$lists, $labels];
    }

    /**
     * The missing positions of these orders, as the list counts them: the orders sharing a box take their shares in turn —
     * one read of the lists, the reservations and the boxes for all (CD-7).
     *
     * @param list<BuildOrder> $orders the orders asked about
     * @param list<BuildOrder> $all    every order, for the box shares
     * @return array<int, int> order → missing positions; 0 for an order without a box
     */
    public function missingOf(array $orders, array $all): array
    {
        $boxes            = array_flip(array_filter(array_map(static fn (BuildOrder $o): int => (int) $o->box, $orders)));
        $related          = array_values(array_filter($all, static fn (BuildOrder $o): bool => in_array($o, $orders, true) || (! $o->isClosed() && isset($boxes[(int) $o->box]))));
        [$lists]          = $this->context($related);
        $ids              = array_map(static fn (BuildOrder $o): int => $o->id, $related);
        $reserved         = $this->service->reservedFor($ids, array_combine($ids, array_map(static fn (BuildOrder $o): ?int => $o->box, $related)));
        $present          = $this->service->presentInEach(array_values(array_keys($boxes)));
        $assigned         = $this->service->assignments($ids);
        $own              = [];

        foreach ($related as $order) {
            $own[$order->id] = $this->service->assignedLists($order, $lists, $assigned[$order->id] ?? []);
        }

        $shares = Demand::boxShares($related, $own, $reserved, $present);
        $out    = [];

        foreach ($orders as $order) {
            $out[$order->id] = $order->box === null ? 0 : Demand::missingRows(Demand::of($order->assemblies, $own[$order->id], $order->count, $this->service->available($order, $reserved[$order->id] ?? [], $shares[$order->id] ?? [])));
        }

        return $out;
    }

    /** admin-post: the page's acts live in {@see OrderActions} (architecture 16.11.: the page draws, the handler acts). */
    public function handle(string $action): void
    {
        (new OrderActions($this, $this->service))->handle($action);
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, string> $made revision → «board · revision»
     * @param array<int, list<array{id: int, thing: int, count: float, fitting: string, ref: string}>> $lists
     * @param array<int, list<array{position: int, thing: int, needed: float, present: float, missing: float, optional: bool, unused: bool}>> $demand
     */
    private function row(BuildOrder $order, array $labels, array $made, array $lists, array $demand): string
    {
        $cells = [
            '<strong><a href="' . esc_url($this->url(['auftrag' => $order->id, 'tab' => $this->tab()])) . '">' . esc_html($order->label) . '</a></strong>',
            esc_html($labels[$order->project] ?? '#' . $order->project),
            esc_html(implode(', ', array_map(static fn (Assembly $a): string => $made[$a->revision] ?? '#' . $a->revision, $order->assemblies))),
            esc_html((string) $order->count),
            $this->stepCell($order, $order->box === null ? 0 : Demand::missingRows($demand)),
            esc_html(implode(' · ', array_map(static fn (Assembly $a): string => Demand::boardsFitted($demand[$a->id] ?? []) . '/' . $a->done, $order->assemblies))),
            $order->box === null ? '<em>' . esc_html__('keins', 'taxmod-manufacturing') . '</em>' : esc_html($labels[$order->box] ?? '#' . $order->box),
            esc_html($order->box === null ? '—' : (string) Demand::missingRows($demand)),
            esc_html($order->started ?? '—'),
        ];

        // Stefan 21.11. 10:32 «aufklappen weg»: the missing positions stand in their column, the name opens the order.
        return ListPage::row(self::FORM, (string) $order->id, $cells, $this->rowButtons($order, $order->box === null ? 0 : Demand::missingRows($demand)), '');
    }

    /**
     * «Board · revision» for each revision, as the order's page names it.
     *
     * @param list<int> $revisions
     * @param array<int, string> $labels
     * @return array<int, string>
     */
    private function boardNames(array $revisions, array $labels): array
    {
        $boards  = $this->service->boardsOfRevisions($revisions);
        $boardOf = $this->service->labelsOf(array_values(array_unique($boards)));
        $names   = [];

        foreach ($revisions as $rev) {
            // Stefan 20.11. 19:51: a bare «#33074» says nothing — the board's name alone when the revision has none.
            $names[$rev] = trim(($boardOf[$boards[$rev] ?? 0] ?? '') . ' · ' . ($labels[$rev] ?? ''), ' ·') ?: '#' . $rev;
        }

        return $names;
    }

    /**
     * The bulk choices of a tab: the steps that tab's orders can take (Stefan 21.11. 10:30), «Wieder aufnehmen» only where the
     * orders have ended.
     *
     * @return array<string, string> action → label
     */
    private function bulkChoices(string $tab): array
    {
        $name   = self::viewLabels();
        $cancel = [self::BULK_CANCEL => __('Abbrechen', 'taxmod-manufacturing')];

        return match ($tab) {
            'order_planned' => [...($this->service->structure()->hasValue('order_staging') ? [self::BULK_STAGING => $name['order_staging'] . ' →'] : []), self::BULK_BUILDING => $name['order_building'] . ' →', ...$cancel],
            'order_staging' => [self::BULK_BACK => '← ' . $name['order_planned'], self::BULK_BUILDING => $name['order_building'] . ' →', ...$cancel],
            'done', 'order_done', 'cancelled', 'order_failed' => [self::BULK_REOPEN => __('Wieder aufnehmen', 'taxmod-manufacturing')],
            default => [...$cancel, self::BULK_REOPEN => __('Wieder aufnehmen', 'taxmod-manufacturing')],
        };
    }

    private function rowButtons(BuildOrder $order, int $missing = 0): string
    {
        $buttons = '';

        $name = self::viewLabels();

        // Stefan 21.11. 10:28: the step back first, «In Bau geben» before «Abbrechen», as arrows; from «Planung» there is no
        // way back (10:31: «man kann nicht zurück ins Projektgebinde»).
        if ($order->step === 'order_staging') {
            $buttons .= $this->dialogForm('back', $order, '← ' . $name['order_planned'], sprintf(__('Zurück nach «%s»', 'taxmod-manufacturing'), $name['order_planned']),
                esc_html__('Die Reservierungen dieses Auftrags werden freigegeben (Storno im Lager). Was schon im Gebinde liegt, bleibt dort.', 'taxmod-manufacturing'));
        }

        if ($order->step === 'order_planned' && $this->service->structure()->hasValue('order_staging')) {
            $buttons .= $this->dialogForm('staging', $order, $name['order_staging'] . ' →', __('In Bereitstellung geben', 'taxmod-manufacturing'),
                esc_html__('Die Teile werden jetzt ins Gebinde umgelagert oder reserviert.', 'taxmod-manufacturing'));
        }

        // Stefan 21.11. 10:28: one «In Bau geben»; when something is missing it warns, and the user decides (D-1301: with a reason).
        if (in_array($order->step, ['order_planned', 'order_staging'], true)) {
            $buttons .= $missing === 0 || $order->box === null
                ? $this->dialogForm('building', $order, $name['order_building'] . ' →', __('In Bau geben', 'taxmod-manufacturing'),
                    esc_html__('Alle Teile sind bereit.', 'taxmod-manufacturing'))
                : $this->dialogForm('building', $order, $name['order_building'] . ' →', __('Trotz Fehlteilen in Bau geben', 'taxmod-manufacturing'),
                    esc_html(sprintf(_n('Achtung: Es fehlt noch %d Position. Der Auftrag bleibt mit «Fehlteile» markiert, bis alles da ist.', 'Achtung: Es fehlen noch %d Positionen. Der Auftrag bleibt mit «Fehlteile» markiert, bis alles da ist.', $missing, 'taxmod-manufacturing'), $missing))
                    . '</p><p><label>' . esc_html__('Grund', 'taxmod-manufacturing') . '<br>' . ListPage::field(InputKind::Line, 'reason', '', ['required' => true, 'size' => 40]) . '</label>');
        }

        if (! $order->isClosed() && $order->isFinished()) {
            $buttons .= $this->dialogForm('done', $order, __('Abschließen', 'taxmod-manufacturing'), __('Abschließen', 'taxmod-manufacturing'),
                esc_html__('Alle Stücke aller Baugruppen sind fertig. Der Auftrag wird als fertig markiert; «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'));
        }

        // His word 17.11. 22:04 «warum ist der button gebinde leer räumen überall in fertigung zu sehen»: what is left goes back
        // once the order has ended, and only when something is left.
        if ($order->box !== null && $order->isClosed() && has_filter(ManufacturingService::RETURN_FILTER) && $this->service->presentIn($order->box) !== []) {
            $buttons .= $this->dialogForm('clear', $order, __('Box leer räumen', 'taxmod-manufacturing'), __('Gebinde leer räumen', 'taxmod-manufacturing'),
                esc_html__('Alles im Gebinde geht zurück an den Platz, von dem es kam. Ein Vorgang, rückgängig über Storno.', 'taxmod-manufacturing'));
        }

        if ($order->isClosed()) {
            return $buttons . $this->dialogForm('reopen', $order, __('Wieder aufnehmen', 'taxmod-manufacturing'), __('Wieder aufnehmen', 'taxmod-manufacturing'),
                esc_html__('Der Auftrag kommt zurück in «Offen», mit dem Stand seiner Stücke.', 'taxmod-manufacturing'));
        }

        return $buttons . $this->dialogForm('cancel', $order, __('Abbrechen', 'taxmod-manufacturing'), __('Abbrechen', 'taxmod-manufacturing'),
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
        $count  = static fn (float $n): string => ListPage::number($n, 2);
        $boxed  = $order->box !== null;
        $form   = self::headFormId($order);
        $scrap  = $boxed && $order->step === 'order_building' && $this->service->canScrap();
        $bulk   = 'taxmod-mf-fitbulk-' . $assembly->id;
        // The gate 18.11.: from «Test» on the fitting is done; it is shown, not entered.
        $closed = $order->isClosed() || $order->step !== 'order_building';
        $a      = (string) $assembly->id;
        $grid   = [];

        foreach ($rows as $row) {
            $position = (string) $row['position'];
            // His report 17.11. «button chaos»: the fields join the master data's form, whose one 💾 saves both.
            $fit      = $row['position'] === 0 || $row['unused'] || $closed
                ? esc_html($count($row['boards'] * $row['count']))
                : ListPage::field(InputKind::Number, 'fitted[' . $a . '][' . $position . ']', $count($row['boards'] * $row['count']), ['form' => $form, 'label' => __('Bestückt (Teile)', 'taxmod-manufacturing'), 'min' => '0', 'max' => $count($row['needed']), 'step' => $count($row['count'])]);
            // D-1403: what acts on rows is a bulk choice («Nicht verwenden», «Wieder verwenden»); the save keeps what is left out.
            $kept     = $row['unused'] ? '<input type="hidden" name="unused[' . esc_attr($a) . '][]" form="' . esc_attr($form) . '" value="' . esc_attr($position) . '">' : '';
            $tick     = $row['position'] === 0 || $closed ? '' : ListPage::field(InputKind::Tick, 'selected[]', $position, ['form' => $bulk, 'label' => __('Auswählen', 'taxmod-manufacturing')]);
            $cells    = [
                $tick,
                esc_html($row['position'] === 0 ? __('Platine', 'taxmod-manufacturing') : (string) ($refs[$row['position']] ?? '')),
                $this->part($row['thing'], $labels, $order),
                esc_html($count($row['needed'])),
                '<span class="taxmod-mf-nowrap">' . $fit . '</span>',
                esc_html($boxed ? $count($row['present']) : '—'),
                $boxed && $row['missing'] > 0.0 ? '<span class="taxmod-mf-status taxmod-mf-status-warn">' . esc_html($count($row['missing'])) . '</span>' : '',
                $row['unused'] ? '<span class="taxmod-mf-status taxmod-mf-status-info">' . esc_html__('nicht verwendet', 'taxmod-manufacturing') . '</span>' . $kept : ($row['optional'] ? esc_html__('optional', 'taxmod-manufacturing') : ''),
            ];

            if ($scrap) {
                $cells[] = $row['position'] === 0 ? '' : ListPage::field(InputKind::Number, 'scrap[' . $a . '][' . $position . ']', '', ['form' => $form, 'label' => __('Ausschuss', 'taxmod-manufacturing'), 'min' => '0', 'step' => '1']);
            }

            // The gate 18.11.: a fitting that is only shown has no tick column.
            $grid[] = $closed ? array_slice($cells, 1) : $cells;
        }

        // The lines of a form, not a list: no filter, grouping or actions column (his report 17.11.).
        $table = ListPage::grid(array_merge($closed ? [] : [[__('Alle wählen', 'taxmod-manufacturing')]], [
            [__('Referenz', 'taxmod-manufacturing')], [__('Teil', 'taxmod-manufacturing')], [__('Bedarf (Teile)', 'taxmod-manufacturing'), GridMarkup::NUMBER],
            [__('Bestückt (Teile)', 'taxmod-manufacturing')], [__('Im Gebinde', 'taxmod-manufacturing'), GridMarkup::NUMBER], [__('Fehlt', 'taxmod-manufacturing'), GridMarkup::NUMBER],
            [__('Option', 'taxmod-manufacturing')],
        ], $scrap ? [[__('Ausschuss', 'taxmod-manufacturing')]] : []), $grid, '', $closed ? '' : 'taxmod-mf-ticks');

        // D-1403: the choices on ticked rows; the save is the master data's one 💾.
        $bar = $closed ? '' : ListPage::bulkBar($bulk, self::NONCE, [
            self::BULK_FIT_FULL => __('Voll bestückt (alle Stück)', 'taxmod-manufacturing'),
            self::BULK_FIT_NONE => __('Bestückt auf 0', 'taxmod-manufacturing'),
            self::BULK_UNUSE    => __('Nicht verwenden', 'taxmod-manufacturing'),
            self::BULK_USE      => __('Wieder verwenden', 'taxmod-manufacturing'),
        ], '', '', false)
            . $this->hidden(['order' => (string) $order->id, 'assembly' => (string) $assembly->id, 'tab' => $this->tab()], $bulk);

        return '<h3>' . esc_html($labels[$assembly->revision] ?? '#' . $assembly->revision)
            . ' · ' . esc_html(sprintf(__('%1$d von %2$d bestückt', 'taxmod-manufacturing'), Demand::boardsFitted($rows), $order->count)) . '</h3>'
            . $bar . $table;
    }

    /**
     * Only before the first completed board of a revision: in which Material-Kategorie (a group under Hardware) its record is
     * made (D-1261; the word his, 17.11. «das ist die material kategorie»). The modeller's node chooser, the category of another
     * revision of the board preselected and shown, never set unseen (architecture 17.11.). It stands in the master data and
     * joins the finish form by its id: the chooser is a dialog of its own, and inside the finish dialog its shade covered
     * that dialog whole, Cancel included (his report 17.11. 21:37 «unktioniert nix noch nihct mal cancel»).
     */
    private function categoryChooser(BuildOrder $order, Assembly $assembly): string
    {
        return $this->choosers[$order->id . ':' . $assembly->id] ??= $this->drawCategoryChooser($order, $assembly);
    }

    private function drawCategoryChooser(BuildOrder $order, Assembly $assembly): string
    {
        if (! $this->service->canReceive() || $this->service->existingMaterial($assembly->revision, $order->kit) !== null) {
            return '';
        }

        $chooser = apply_filters(\Taxmod\WordPress\Plugin::NODE_CHOOSER_FILTER, '', [
            'name'        => 'category[' . $assembly->id . ']',
            'root'        => $this->service->structure()->node('hardware'),
            'preselected' => $this->service->suggestedSparte($assembly->revision) ?? 0,
            'form'        => self::finishFormId($order),
        ]);

        return is_string($chooser) ? $chooser : '';
    }

    private static function finishFormId(BuildOrder $order): string
    {
        return 'taxmod-mf-finish-form-' . $order->id;
    }

    /**
     * D-1419 «Fertigung abschließen →»: how many of the fully fitted boards to complete (all of them preset); a partial
     * completion keeps the rest of the order in «In Fertigung» (his words 17.11. 16:52/16:53).
     *
     * @param array<int, string> $labels
     */
    private function finishDialog(BuildOrder $order, array $labels): string
    {
        $ready = $this->service->readyToFinish($order);
        $max   = $ready === [] ? 0 : min($ready);
        $done  = $order->assemblies === [] ? 0 : min(array_map(static fn (Assembly $a): int => $a->done, $order->assemblies));
        $label = ($order->kit ? __('Kits abschließen', 'taxmod-manufacturing') : __('Fertigung abschließen', 'taxmod-manufacturing')) . ' →';

        if ($max < 1) {
            return ListPage::button($label, '', true, attributes: ['type' => 'button', 'disabled' => 'disabled', 'title' => __('Erst wenn ein Stück voll bestückt ist.', 'taxmod-manufacturing')]);
        }

        return $this->finishForm($order, $labels, $max, $done, $label);
    }

    /** @param array<int, string> $labels */
    private function finishForm(BuildOrder $order, array $labels, int $max, int $done, string $label): string
    {
        $categories = array_filter($order->assemblies, fn (Assembly $assembly): bool => $this->categoryChooser($order, $assembly) !== '');

        $body = $order->kit ? $this->kitBody($order, $max, $done, $categories, $labels) : '<div>' . esc_html(sprintf(__('Voll bestückt und offen: %1$d. Abgeschlossen sind %2$d von %3$d.', 'taxmod-manufacturing'), $max, $done, $order->count)) . '</div>'
            . '<div class="taxmod-mf-field"><label>' . esc_html__('Stücke abschließen', 'taxmod-manufacturing') . ' ' . ListPage::field(InputKind::Number, 'boards', (string) $max, ['min' => 1, 'max' => $max, 'required' => true]) . '</label></div>'
            . $this->minutesField($order)
            . ($categories === [] ? '' : '<div>' . esc_html__('Die Material-Kategorie des Stücks steht in den Stammdaten.', 'taxmod-manufacturing') . '</div>')
            . $this->systemsLines($categories, $labels)
            . '<div class="description">' . esc_html__('Bucht den Verbrauch ihrer Teile aus dem Gebinde und legt die Stücke ungeprüft auf den Platz «Test». Weniger als alle: der Rest bleibt in Bau, Gebinde und Reservierungen bleiben. Alle: der Auftrag geht in «Test». Ein Vorgang, zurücknehmen über die Änderungsnummer.', 'taxmod-manufacturing') . '</div>';

        return '<form method="post" id="' . esc_attr(self::finishFormId($order)) . '" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-mf-inline">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'order' => (string) $order->id, 'tab' => $this->tab(), 'view' => 'detail'])
            . ListPage::dialogAction('taxmod-mf-finish-' . $order->id, $label, sprintf(__('%1$s: %2$s', 'taxmod-manufacturing'), $order->kit ? __('Kits abschließen', 'taxmod-manufacturing') : __('Fertigung abschließen', 'taxmod-manufacturing'), $order->label), $body, 'do', 'finish', __('Abschließen', 'taxmod-manufacturing'))
            . '</form>';
    }

    /**
     * D-1460: at the first completion of a revision its plan's «Systeme» are taken over once into the new material — said here
     * beforehand in one line, changed afterwards in the material (the modeller has no choice of several for modules yet).
     *
     * @param array<int, Assembly> $first the assemblies whose material this completion makes
     * @param array<int, string>   $labels
     */
    private function systemsLines(array $first, array $labels): string
    {
        $choices = $first === [] ? [] : $this->service->systemChoices();
        $html    = '';

        foreach ($choices === [] ? [] : $first as $assembly) {
            $names = array_values(array_intersect_key($choices, array_flip($this->service->planSystems($assembly->revision))));
            $html .= '<div>' . esc_html(($labels[$assembly->revision] ?? '#' . $assembly->revision) . ': ' . ($names === []
                ? __('Systeme: keine im Bauplan', 'taxmod-manufacturing')
                : sprintf(__('Systeme vom Bauplan: %s — werden einmal ins neue Material übernommen; ändern im Material.', 'taxmod-manufacturing'), implode(', ', $names)))) . '</div>';
        }

        return $html;
    }

    /**
     * D-1428: n of N kits packed — board and parts used up from the box, the kits into stock at the place chosen here (INF-134:
     * the place is his to decide; until then he picks it in the dialog), priced like a board with the packing time (D-1425).
     */
    /**
     * @param array<int, Assembly> $categories the assemblies whose material this completion makes
     * @param array<int, string>   $labels
     */
    private function kitBody(BuildOrder $order, int $max, int $done, array $categories, array $labels): string
    {
        $places  = $this->service->kitPlaces($order);

        return '<div>' . esc_html(sprintf(__('Offen: %1$d. Abgeschlossen sind %2$d von %3$d Kits.', 'taxmod-manufacturing'), $max, $done, $order->count)) . '</div>'
            . '<div class="taxmod-mf-field"><label>' . esc_html__('Kits abschließen', 'taxmod-manufacturing') . ' ' . ListPage::field(InputKind::Number, 'boards', (string) $max, ['min' => 1, 'max' => $max, 'required' => true]) . '</label></div>'
            . '<div class="taxmod-mf-field"><label>' . esc_html__('Lagerplatz der Kits', 'taxmod-manufacturing') . ' ' . SelectMarkup::of('place', $places, null, false, '', ['required' => 'required']) . '</label></div>'
            . $this->minutesField($order)
            . ($categories !== [] ? '<div>' . esc_html__('Die Material-Kategorie des Kits steht in den Stammdaten.', 'taxmod-manufacturing') . '</div>' : '')
            // D-1460 for kits too (his word 19.11. 07:01).
            . $this->systemsLines($categories, $labels)
            . '<div class="description">' . esc_html__('Bucht den Verbrauch von Platine und Teilen aus dem Gebinde und legt die Kits auf den gewählten Platz. Weniger als alle: der Rest bleibt in Bau. Alle: der Auftrag ist fertiggestellt. Ein Vorgang, zurücknehmen über die Änderungsnummer.', 'taxmod-manufacturing') . '</div>';
    }

    /**
     * D-1425: the production time per board, preset from the revision's last completion, else empty — never a guessed number.
     * Only when the stock prices; without it the completion books as before.
     */
    private function minutesField(BuildOrder $order): string
    {
        if (! $this->service->canPrice()) {
            return '';
        }

        $last = null;

        foreach ($order->assemblies as $assembly) {
            $last ??= $this->service->lastMinutes($assembly->revision, $order->kit);
        }

        $settings = SettingsTab::settings();
        $money    = static fn (float $n): string => ListPage::money($n, 'EUR');
        // D-1475: the stopwatch's time, the running stretch included, comes in as the time of all boards of this completion.
        $timed    = $this->service->canTime();
        $recorded = $timed ? Stopwatch::minutes($order, ManufacturingService::now()) : 0.0;
        $forAll   = $recorded > 0.0;
        $value    = $forAll ? round($recorded, 2) : $last;
        $label    = match (true) {
            $timed      => $order->kit ? __('Packzeit', 'taxmod-manufacturing') : __('Produktionszeit', 'taxmod-manufacturing'),
            $order->kit => __('Packzeit je Kit', 'taxmod-manufacturing'),
            default     => __('Produktionszeit je Stück', 'taxmod-manufacturing'),
        };
        $scope    = $timed ? '<div class="taxmod-mf-field"><label>' . esc_html__('Zeit gilt für', 'taxmod-manufacturing') . ' ' . SelectMarkup::of('time_for', [
                'all' => $order->kit ? __('alle Kits dieses Abschlusses (wird geteilt)', 'taxmod-manufacturing') : __('alle Stücke dieses Abschlusses (wird geteilt)', 'taxmod-manufacturing'),
                'one' => $order->kit ? __('ein Kit', 'taxmod-manufacturing') : __('ein Stück', 'taxmod-manufacturing'),
            ], $forAll ? 'all' : 'one', false) . '</label></div>'
            . ($forAll ? '<div>' . esc_html(sprintf(__('Erfasst mit der Zeitmessung: %s h. Wird mit dem Abschluss auf 0 gesetzt.', 'taxmod-manufacturing'), Stopwatch::clock($recorded))) . '</div>' : '') : '';

        return '<div class="taxmod-mf-field"><label>' . esc_html($label) . ' ' . ListPage::field(InputKind::Decimal, 'minutes', $value === null ? '' : (string) round($value, 2), ['required' => true, 'size' => 6]) . ' ' . esc_html__('Minuten', 'taxmod-manufacturing') . '</label></div>'
            . $scope
            /* translators: 1: wage per hour, 2: set-up hours per order */
            . '<div class="description">' . esc_html(sprintf($order->kit
                /* translators: 1: wage per hour, 2: set-up hours per order */
                ? __('Preis je Kit = Platine und Teile (Kaufpreise der verbrauchten Posten) + Bereitstellung (%2$s h je Auftrag, verteilt) + Packzeit × %1$s € je Stunde. Einstellbar unter Produktion.', 'taxmod-manufacturing')
                : __('Preis je Stück = Teile (Kaufpreise der verbrauchten Posten) + Bereitstellung (%2$s h je Auftrag, verteilt) + Zeit × %1$s je Stunde. Einstellbar unter Produktion.', 'taxmod-manufacturing'), $money($settings->wage), ListPage::number($settings->setupHours, 2))) . '</div>';
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
        $count  = static fn (float $n): string => ListPage::number($n, 2);
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

        $formId = 'taxmod-mf-new-form' . ($box > 0 ? '-' . $box : '');
        // His word 2026-11-15: «nimm … tree chooser der hat das ja alles schon und schränke auf die projekte ein» — the modeller's
        // record chooser, limited to the projects; its choice is a radio «project», which the board parts below follow (D-1311).
        $chooser = $project === 0 && has_filter('taxmod_record_chooser')
            ? (string) apply_filters('taxmod_record_chooser', '', [
                'name'       => 'project',
                'node'       => $this->service->structure()->node('project'),
                'label_field' => $this->service->structure()->field('label'),
                'unpickable' => array_keys(array_diff_key($this->projects, $this->boards)),
                'form'       => $formId,
                // His word 2026-11-15: the chooser inline in the dialog, not a second dialog on top of it (D-1084/D-1090 switch).
                'inline'     => true,
            ])
            : '';
        $picker = $chooser !== '' ? $chooser : '<select name="project" required>' . $options . '</select>';
        $body   = '<div class="taxmod-dialog-field taxmod-mf-field">' . esc_html__('Projekt', 'taxmod-manufacturing') . '<br>' . $picker . '</div>'
            . '<label class="taxmod-dialog-field taxmod-mf-field">' . esc_html__('Stückzahl', 'taxmod-manufacturing') . '<br>' . ListPage::field(InputKind::Number, 'count', '1', ['min' => 1, 'step' => 1, 'required' => true]) . '</label>';

        // D-1428: «Bestücken» by default; «Kit» packs board and parts for sale.
        if ($this->service->canKit()) {
            $body .= '<div class="taxmod-dialog-field taxmod-mf-field">' . esc_html__('Art', 'taxmod-manufacturing') . '<br>'
                . ListPage::field(InputKind::Choice, 'art', 'fitting', ['choices' => ['fitting' => __('Bestücken', 'taxmod-manufacturing'), 'kit' => __('Kit (Platine + Teile zum Verkauf)', 'taxmod-manufacturing')]]) . '</div>';
        }

        // D-1311: the boards of the chosen project show in the dialog itself; the server still takes only that project's.
        foreach (array_intersect_key($this->boards, $projects) as $project => $boards) {
            $body .= ListPage::shownBy('project', (string) $project, '<fieldset><legend>' . esc_html__('Baupläne', 'taxmod-manufacturing') . '</legend>' . $this->boardBoxes($boards) . '</fieldset>');
        }

        $body .= $this->hidden(['choosing' => '1']);

        return '<form method="post" id="' . esc_attr($formId) . '" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'tab' => $this->tab()] + ($box > 0 ? ['box' => (string) $box] : []))
            . ListPage::dialogAction('taxmod-mf-new' . ($box > 0 ? '-' . $box : ''), $box > 0 ? __('+ Auftrag', 'taxmod-manufacturing') : __('Neuer Auftrag', 'taxmod-manufacturing'), __('Anlegen: Fertigungsauftrag', 'taxmod-manufacturing'), $body, 'do', 'create', __('Anlegen', 'taxmod-manufacturing'))
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
            . '<p><strong>' . esc_html(sprintf(__('Welche Baupläne von «%s» sollen gebaut werden?', 'taxmod-manufacturing'), $name)) . '</strong></p>'
            . ListPage::toolbar([
                ListPage::button(__('Anlegen', 'taxmod-manufacturing'), '', true),
                ListPage::button(__('Abbrechen', 'taxmod-manufacturing'), $this->url(['tab' => $this->tab()])),
            ])
            . $boxes
            . '</form></div>';
    }

    /** D-1308: «Speichern» with the diskette, first in the toolbar above its form. */
    private function saveButton(string $form = ''): string
    {
        $button = ControlMarkup::button(Control::saving('submit', '1', __('Speichern', 'taxmod-manufacturing'), __('Speichern', 'taxmod-manufacturing')));

        // A save that stands in a bar outside its form joins it by `form=` (D-1403: one row).
        return $form === '' ? $button : preg_replace('/^<button /', '<button form="' . esc_attr($form) . '" ', $button, 1) ?? $button;
    }

    /** @param array<int, string> $boards newest revision id => label; a single board comes ticked */
    private function boardBoxes(array $boards): string
    {
        $only  = count($boards) === 1;
        $boxes = '';

        foreach ($boards as $revision => $label) {
            $boxes .= '<p><label>' . ListPage::field(InputKind::Tick, 'boards[]', (string) $revision, ['checked' => (bool) ($only)]) . ' ' . esc_html($label) . '</label></p>';
        }

        return $boxes;
    }

    /**
     * D-1475: start or stop, and the time recorded. The server counts; the page only lets the shown time run on.
     */
    private function timerControl(BuildOrder $order, string $form): string
    {
        $runs    = Stopwatch::runs($order);
        $minutes = Stopwatch::minutes($order, ManufacturingService::now());
        $button  = ControlMarkup::button($runs
            ? new Control('do', 'timer_stop', __('Zeit anhalten', 'taxmod-manufacturing'), __('Zeit anhalten', 'taxmod-manufacturing'), true, false, 'controls-pause', $form)
            : new Control('do', 'timer_start', __('Zeit starten', 'taxmod-manufacturing'), __('Zeit starten', 'taxmod-manufacturing'), true, false, 'controls-play', $form));

        return $button . ' <span class="taxmod-mf-timer" data-taxmod-mf-timer="' . esc_attr($runs ? (string) round($minutes * 60) : '') . '" title="' . esc_attr__('Erfasste Zeit (Stunden:Minuten)', 'taxmod-manufacturing') . '">'
            . esc_html(Stopwatch::clock($minutes)) . ' h</span>';
    }

    /** D-1464 «Fehlgeschlagen …»: the plan does not work — the whole order, with what was found. */
    private function failDialog(BuildOrder $order): string
    {
        return $this->dialogForm('failed', $order, __('Fehlgeschlagen …', 'taxmod-manufacturing'), __('Fehlgeschlagen', 'taxmod-manufacturing'),
            '<label>' . esc_html__('Befund', 'taxmod-manufacturing') . '<br>' . ListPage::field(InputKind::Line, 'finding', '', ['required' => true, 'size' => 40]) . '</label></p>'
            . '<p class="description">' . esc_html__('Der Bauplan funktioniert nicht. Die Revision gilt dann als «Nicht bestätigt». Ein einzelnes Stück, das nicht geht, ist Ausschuss: «Verschrotten» unter Positionen. «Wieder aufnehmen» nimmt es zurück.', 'taxmod-manufacturing'));
    }

    private function dialogForm(string $do, BuildOrder $order, string $opener, string $title, string $body): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-mf-inline">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'order' => (string) $order->id, 'tab' => $this->tab(), 'view' => absint($_GET['auftrag'] ?? 0) === $order->id ? 'detail' : ''])
            // D-1436: the title is the verb, and the order it acts on.
            . ListPage::dialogAction('taxmod-mf-' . $do . '-' . $order->id, $opener, sprintf(__('%1$s: %2$s', 'taxmod-manufacturing'), $title, $order->label), '<p>' . $body . '</p>', 'do', $do, $opener)
            . '</form>';
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

    private function notice(): string
    {
        $error = sanitize_text_field(rawurldecode(wp_unslash((string) ($_GET['err'] ?? ''))));

        if ($error !== '') {
            return '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        $text = [
            'created'   => __('Auftrag angelegt.', 'taxmod-manufacturing'),
            'saved'     => __('Baugruppe gespeichert.', 'taxmod-manufacturing'),
            'saved_booked'   => __('Baugruppe gespeichert, Verbrauch im Lager gebucht.', 'taxmod-manufacturing'),
            'saved_unbooked' => __('Baugruppe gespeichert. Der Verbrauch ist noch nicht gebucht: das Lager bietet die Buchung noch nicht an.', 'taxmod-manufacturing'),
            'box'       => __('Gebinde gespeichert.', 'taxmod-manufacturing'),
            'head'      => __('Auftrag gespeichert.', 'taxmod-manufacturing'),
            'options'   => __('Aufbau-Optionen gespeichert.', 'taxmod-manufacturing'),
            'readded'   => __('Position wieder hinzugefügt.', 'taxmod-manufacturing'),
            'stepped_back' => __('Einen Schritt zurück.', 'taxmod-manufacturing'),
            'stepped'      => __('Die gewählten Aufträge sind weitergestellt.', 'taxmod-manufacturing'),
            'stepped_short' => __('Aufträge mit Fehlteilen sind nicht in Bau gegangen: jeden einzeln mit Grund in Bau geben.', 'taxmod-manufacturing'),
            'reordered' => __('Fehlendes als Bedarf im Einkauf angelegt.', 'taxmod-manufacturing'),
            'nothing_missing' => __('Es fehlt nichts, was nirgends am Lager liegt.', 'taxmod-manufacturing'),
            'picked'    => __('Abgehakte Teile ins Gebinde umgebucht.', 'taxmod-manufacturing'),
            'nothing_picked' => __('Nichts abgehakt, nichts gebucht.', 'taxmod-manufacturing'),
            'closed'    => __('Auftrag abgeschlossen. «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'),
            'cancelled' => __('Abgebrochen. «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'),
            'reopened'  => __('Wieder aufgenommen.', 'taxmod-manufacturing'),
            'failed'    => __('Fehlgeschlagen eingetragen; die Revision gilt als «Nicht bestätigt».', 'taxmod-manufacturing'),
            'staging'   => __('In Bereitstellung.', 'taxmod-manufacturing'),
            'reserved'  => __('Abgehakte Teile für den Auftrag reserviert.', 'taxmod-manufacturing'),
            'changed'   => __('Reservierung geändert.', 'taxmod-manufacturing'),
            'released'  => __('Reservierung aufgehoben (Storno im Lager).', 'taxmod-manufacturing'),
            'assigned'  => __('Der Position zugewiesen und für den Auftrag reserviert.', 'taxmod-manufacturing'),
            'rounded'   => __('Rundkontakt-Leisten für die fehlenden Positionen reserviert.', 'taxmod-manufacturing'),
            'no_round'  => __('Keine Rundkontakt-Leiste derselben Art in genug Menge am Lager.', 'taxmod-manufacturing'),
            'cleared'   => __('Gebinde leer geräumt, alles zurück an seinen Platz.', 'taxmod-manufacturing'),
            'building'  => __('In Bau.', 'taxmod-manufacturing'),
            'kits_finished' => __('Kits abgeschlossen: Verbrauch von Platine und Teilen gebucht, Kits im Lager. Der Auftrag ist fertig.', 'taxmod-manufacturing'),
            'finished'      => __('Fertigung abgeschlossen: Verbrauch gebucht, Stücke ungeprüft auf «Test». Der Auftrag ist jetzt in Test.', 'taxmod-manufacturing'),
            'kits_part'     => __('Kits teilweise abgeschlossen: Verbrauch gebucht, Kits im Lager. Der Rest bleibt in Bau.', 'taxmod-manufacturing'),
            'finished_part' => __('Teil abgeschlossen: Verbrauch gebucht, Stücke ungeprüft auf «Test». Der Rest bleibt in Bau.', 'taxmod-manufacturing'),
            'test_passed'   => __('Test bestanden eingetragen.', 'taxmod-manufacturing'),
            'test_failed'   => __('Nacharbeit eingetragen; das Stück wartet auf Nacharbeit und neuen Test.', 'taxmod-manufacturing'),
            'scrapped'      => __('Verschrottet (Defekt/Ausschuss im Lager).', 'taxmod-manufacturing'),
            'passed_moved'  => __('Bestanden: fertig und auf «Wareneingang Produktion» umgebucht.', 'taxmod-manufacturing'),
            'retested'      => __('Nacharbeit erledigt: das Stück ist wieder in Test, ungetestet.', 'taxmod-manufacturing'),
            'finished_all'  => __('Fertig: alle offenen Stücke bestanden und auf «Wareneingang Produktion» umgebucht.', 'taxmod-manufacturing'),
            'back_to_test'  => __('Zurück in Bau: die fertigen Stücke liegen wieder ungetestet auf «Test».', 'taxmod-manufacturing'),
            'timer_started' => __('Zeitmessung läuft.', 'taxmod-manufacturing'),
            'timer_stopped' => __('Zeitmessung angehalten, Zeit summiert.', 'taxmod-manufacturing'),
        ][sanitize_key((string) ($_GET['msg'] ?? ''))] ?? '';
        $act  = absint($_GET['act'] ?? 0);

        if ($text !== '' && $act > 0) {
            /* translators: %d: the change number of the modeller (D-1410) */
            $text .= ' ' . sprintf(__('Änderung Nr. %d, zurücknehmen unter Taxonomy Modeller › Änderungen.', 'taxmod-manufacturing'), $act);
        }

        // D-1460: the new material named with the systems taken over, linked where they are changed.
        $made = absint($_GET['made'] ?? 0);
        $tail = '';

        if ($text !== '' && $made > 0) {
            $names = array_values(array_intersect_key($this->service->systemChoices(), array_flip(array_map('absint', explode(',', (string) ($_GET['systems'] ?? ''))))));
            $url   = $this->service->stockUrl($made);
            $tail  = ' ' . esc_html($names === [] ? __('Systeme: keine im Bauplan.', 'taxmod-manufacturing') : sprintf(__('Systeme übernommen: %s.', 'taxmod-manufacturing'), implode(', ', $names)))
                . ($url === null ? '' : ' <a href="' . esc_url($url) . '">' . esc_html__('Neues Material öffnen', 'taxmod-manufacturing') . '</a>');
        }

        return $text === '' ? '' : '<div class="notice notice-success"><p>' . esc_html($text) . $tail . '</p></div>';
    }

    public function message(ManufacturingError $error): string
    {
        return match ($error->reason) {
            'not_configured'           => sprintf(__('Im Modell fehlt %s.', 'taxmod-manufacturing'), $error->detail),
            'shortage_without_reason'  => __('Es fehlen noch Teile: bitte einen Grund angeben.', 'taxmod-manufacturing'),
            'no_board_chosen'          => __('Kein Bauplan gewählt.', 'taxmod-manufacturing'),
            'project_without_boards'   => __('Das Projekt hat keinen Bauplan mit Revision.', 'taxmod-manufacturing'),
            'count_not_positive'       => __('Die Stückzahl muss eine ganze Zahl ab 1 sein.', 'taxmod-manufacturing'),
            /* translators: %s: the parts the box lacks, «2 × name, …» */
            'short_at_finish'          => sprintf(__('Im Gebinde fehlt für «Fertig»: %s. Erst umbuchen oder «Aus Bestand wählen», dann fertig melden.', 'taxmod-manufacturing'), $error->detail),
            'due_not_date'             => __('Der Wunschtermin ist kein gültiges Datum.', 'taxmod-manufacturing'),
            'count_below_done'         => __('Die Stückzahl darf nicht unter die fertigen Stücke fallen.', 'taxmod-manufacturing'),
            'unknown_order'            => __('Diesen Auftrag gibt es nicht.', 'taxmod-manufacturing'),
            'assembly_not_of_order'    => __('Diese Baugruppe gehört nicht zum Auftrag.', 'taxmod-manufacturing'),
            'boards_out_of_range'      => __('Eine Stückzahl liegt außerhalb von 0 bis zur Stückzahl.', 'taxmod-manufacturing'),
            'done_beyond_fitted'       => __('Fertig können höchstens so viele Stücke sein, wie voll bestückt sind.', 'taxmod-manufacturing'),
            'sparte_needed'            => __('Für das erste fertige Stück dieser Revision bitte die Material-Kategorie wählen.', 'taxmod-manufacturing'),
            'not_an_amount'            => __('Die Produktionszeit je Stück fehlt oder ist keine Zahl ab 0 (Minuten, z. B. 12 oder 7,5).', 'taxmod-manufacturing'),
            'text_needed'              => $error->detail === 'reason' ? __('Bitte einen Grund für das Verschrotten angeben; nichts gebucht.', 'taxmod-manufacturing') : __('Bitte eintragen, was beim Test aufgefallen ist; nichts gebucht.', 'taxmod-manufacturing'),
            /* translators: %s: asked > ready */
            'not_ready_to_finish'      => sprintf(__('So viele Stücke sind nicht voll bestückt und offen (%s).', 'taxmod-manufacturing'), $error->detail),
            'no_act_bracket'           => __('Der Abschluss braucht einen neueren Taxonomy Modeller (ein Vorgang für alle Buchungen); nichts gebucht.', 'taxmod-manufacturing'),
            'tests_open'               => __('Erst wenn jedes Stück den Test bestanden hat oder verschrottet ist.', 'taxmod-manufacturing'),
            'nothing_selected'         => __('Kein Stück dieses Auftrags angehakt.', 'taxmod-manufacturing'),
            'stock_refused'            => sprintf(__('Das Lager hat die Buchung abgelehnt, nichts gespeichert: %s', 'taxmod-manufacturing'), $error->detail),
            'assemblies_not_done'      => __('Erst wenn alle Stücke fertig sind, lässt sich der Auftrag abschließen.', 'taxmod-manufacturing'),
            'position_not_on_revision' => __('Nur optionale Positionen der Stückliste lassen sich weglassen.', 'taxmod-manufacturing'),
            'head_fixed'               => __('Ein abgeschlossener Auftrag behält seine Stückzahl.', 'taxmod-manufacturing'),
            'readd_after_use'          => __('Von dieser Baugruppe ist schon etwas verbraucht; die Position kommt nur noch über eine Nachbuchung dazu.', 'taxmod-manufacturing'),
            'no_step_back'             => __('Zurück geht nur, solange noch nichts bestückt ist; danach über «Storno».', 'taxmod-manufacturing'),
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
            'order_planned'   => __('in Planung', 'taxmod-manufacturing'),
            'order_staging'   => __('in Bereitstellung', 'taxmod-manufacturing'),
            'order_building'  => __('in Bau', 'taxmod-manufacturing'),
            'order_test'      => __('in Test', 'taxmod-manufacturing'),
            'order_done'      => __('fertig', 'taxmod-manufacturing'),
            'order_failed'    => __('fehlgeschlagen', 'taxmod-manufacturing'),
            'order_cancelled' => __('abgebrochen', 'taxmod-manufacturing'),
        ][$step] ?? $step;
    }

    public function tab(): string
    {
        $slug = sanitize_key((string) ($_GET['module'] ?? ''));
        // The navigation may send an empty «tab»; then the menu point's slug names the view.
        $tab  = sanitize_key((string) ($_REQUEST['tab'] ?? '')) ?: (array_search($slug, self::VIEWS, true) ?: 'boxes');

        return in_array($tab, self::TABS, true) ? $tab : 'boxes';
    }

    private function inTab(BuildOrder $order, string $tab): bool
    {
        return match ($tab) {
            'done', 'order_done' => $order->step === 'order_done',
            'cancelled'          => $order->step === 'order_cancelled',
            'order_planned', 'order_staging', 'order_building', 'order_test', 'order_failed' => $order->step === $tab,
            'order_rework'       => $order->step === 'order_test' && $this->service->inRework($order->id),
            'all'                => true,
            default     => ! $order->isClosed(),
        };
    }

    /** A refusal (capability, nonce) as a message on the page instead of a bare error page (D-1254). */
    public function refuse(string $message): never
    {
        ListPage::fail($message, $this->url(['tab' => sanitize_key((string) ($_POST['tab'] ?? ''))]));
    }

    /** @param array<string, string|int> $args */
    public function url(array $args): string
    {
        $view = (string) ($args['tab'] ?? '');

        if (isset(self::VIEWS[$view])) {
            $args['module'] = self::VIEWS[$view];
            unset($args['tab']);
        }

        // The arguments win: a view names its own menu point's slug.
        return add_query_arg(array_filter($args + ['page' => 'taxmod-lager-modul', 'module' => self::SLUG], static fn ($v): bool => $v !== '' && $v !== 0), admin_url('admin.php'));
    }
}
