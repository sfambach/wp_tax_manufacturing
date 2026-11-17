<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Demand;
use TaxmodManufacturing\Core\Staging;
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
    /** D-1403: the pick list's choices of its one bulk selection. */
    public const BULK_PICK = StagingSection::PICK;

    public const BULK_RESERVE = StagingSection::RESERVE;

    /** D-1403: the fitting table's choices of its one bulk selection (his word 17.11. «keine kästchen am anfang für bulk operation»). */
    public const BULK_FIT_FULL = 'taxmod_manufacturing_fit_full';

    public const BULK_FIT_NONE = 'taxmod_manufacturing_fit_none';

    public const BULK_UNUSE = 'taxmod_manufacturing_unuse';

    public const BULK_USE = 'taxmod_manufacturing_use';

    public const FITTING = [self::BULK_FIT_FULL, self::BULK_FIT_NONE, self::BULK_UNUSE, self::BULK_USE];

    public const ACTIONS = [self::ACTION, self::BULK_CANCEL, self::BULK_REOPEN, StagingSection::PICK, StagingSection::RESERVE, StagingSection::CHANGE, StagingSection::RELEASE, ...self::FITTING];

    public const NONCE = 'taxmod_manufacturing';

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

        foreach ($shown as $order) {
            $rows .= $this->row($order, $labels, $own[$order->id], Demand::of($order->assemblies, $own[$order->id], $order->count, $this->service->available($order, $reserved[$order->id] ?? [], $shares[$order->id] ?? [])));
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

        if (! method_exists(ListPage::class, 'record')) {
            return '<div class="notice notice-error"><p>' . esc_html__('Die Auftragsansicht braucht den Taxonomy Modeller ab 0.2.101.', 'taxmod-manufacturing') . '</p></div>';
        }

        $order   = $found[0];
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
        $started = in_array($order->step, ['order_building', 'order_done'], true) || ($order->step === 'order_cancelled' && $order->started !== null);
        $form    = 'taxmod-mf-step-' . $order->id;
        $steps   = array_values(array_filter([
            ['order_planned', $name['order_planned']],
            $staging ? ['order_staging', $name['order_staging']] : null,
            ['order_building', $name['order_building']],
            [['order_done', $name['order_done']], ['order_cancelled', $name['cancelled']]],
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
            $previous === ''                                       => ListPage::stepButton(__('Liste', 'taxmod-manufacturing'), false, $list),
            $previous === 'order_planned' && $held !== []          => $this->dialogForm('back', $order, '← ' . $name[$previous], sprintf(__('Zurück nach «%s»?', 'taxmod-manufacturing'), $name[$previous]),
                esc_html(sprintf(_n('%d Reservierung dieses Auftrags wird freigegeben (Storno im Lager). Was schon im Gebinde liegt, bleibt dort.', '%d Reservierungen dieses Auftrags werden freigegeben (Storno im Lager). Was schon im Gebinde liegt, bleibt dort.', count($held), 'taxmod-manufacturing'), count($held)))),
            default                                                => ListPage::stepButton($name[$previous], false, '', 'do', 'back', $form),
        };

        $forward = match (true) {
            $order->step === 'order_planned' && $staging => ListPage::stepButton($name['order_staging'], true, '', 'do', 'staging', $form),
            in_array($order->step, ['order_planned', 'order_staging'], true) => $missing > 0 && $order->box !== null
                ? $this->dialogForm('building', $order, $name['order_building'] . ' →', __('In Fertigung geben, obwohl Teile fehlen?', 'taxmod-manufacturing'),
                    esc_html(sprintf(_n('Es fehlt noch %d Position. Der Auftrag bleibt mit «Fehlteile» markiert, bis alles da ist.', 'Es fehlen noch %d Positionen. Der Auftrag bleibt mit «Fehlteile» markiert, bis alles da ist.', $missing, 'taxmod-manufacturing'), $missing))
                    . '</p><p><label>' . esc_html__('Grund', 'taxmod-manufacturing') . '<br><input type="text" class="regular-text" name="reason" required></label>')
                : ListPage::stepButton($name['order_building'], true, '', 'do', 'building', $form),
            $order->step === 'order_building' && $order->isFinished() => ListPage::stepButton($name['order_done'], true, '', 'do', 'done', $form),
            default => '',
        };

        $actions = [];

        if ($order->box !== null && has_filter(ManufacturingService::RETURN_FILTER)) {
            $actions[] = $this->dialogForm('clear', $order, __('Gebinde leer räumen', 'taxmod-manufacturing'), __('Gebinde leer räumen?', 'taxmod-manufacturing'),
                esc_html__('Alles im Gebinde geht zurück an den Platz, von dem es kam. Ein Vorgang, rückgängig über Storno.', 'taxmod-manufacturing'));
        }

        $actions[] = $order->isClosed()
            ? $this->dialogForm('reopen', $order, __('Wieder aufnehmen', 'taxmod-manufacturing'), __('Auftrag wieder aufnehmen?', 'taxmod-manufacturing'),
                esc_html__('Der Auftrag kommt zurück, mit dem Stand seiner Platinen.', 'taxmod-manufacturing'))
            : $this->dialogForm('cancel', $order, __('Abbrechen', 'taxmod-manufacturing'), __('Auftrag abbrechen?', 'taxmod-manufacturing'),
                esc_html__('Nichts wird gelöscht; «Wieder aufnehmen» holt ihn zurück.', 'taxmod-manufacturing'));

        $sections = [];
        // His word 2026-11-15: taking parts out belongs to «Bereitstellung», not to «Angelegt» (without that stage in the model it stays at «Angelegt»).
        $picking  = $order->step === 'order_staging' || ($order->step === 'order_planned' && ! $staging) || $order->step === 'order_building';
        $picks    = $picking ? (new StagingSection($this->service, self::NONCE, self::ACTION, $this->tab()))->render($order, $demand, $plain, $share, $held, $labels) : '';

        if ($picks !== '') {
            $sections[] = ['title' => __('Material bereitstellen', 'taxmod-manufacturing'), 'html' => $picks, 'open' => ! $started];
        }

        // His word 2026-11-15: at «Angelegt» only the build options are chosen; everything else happens from «Bereitstellung» on.
        $options = $order->step === 'order_planned' ? $this->optionsForm($order, $labels, $demand) : '';

        if ($options !== '') {
            $sections[] = ['title' => __('Aufbau: welche Positionen gebaut werden', 'taxmod-manufacturing'), 'html' => $options];
        }

        $leftOut = $order->isClosed() || $order->step === 'order_planned' ? '' : $this->leftOut($order, $labels, $demand);

        if ($leftOut !== '') {
            $sections[] = ['title' => __('Nicht für den Aufbau ausgewählt', 'taxmod-manufacturing'), 'html' => $leftOut, 'open' => ! $started];
        }

        if ($started) {
            $fitting = '';

            foreach ($order->assemblies as $assembly) {
                $fitting .= $this->assemblyForm($order, $assembly, $labels, $lists[$assembly->revision] ?? [], $demand[$assembly->id] ?? []);
            }

            $sections[] = ['title' => __('Bestückung', 'taxmod-manufacturing'), 'html' => $fitting];
        }

        return '<form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'order' => (string) $order->id, 'tab' => $this->tab(), 'view' => 'detail']) . '</form>'
            . ListPage::record($order->label, $steps, $order->step, $back, $forward, $actions, $this->headForm($order, $labels, $missing), $sections);
    }

    /**
     * Every position of each board, ticked = built (his words 2026-11-15: «auswählen, welche wir aufbauen wollen», «den Sockel
     * weglassen»). What is not ticked is left out, as «nicht verwenden» at the fitting.
     *
     * @param array<int, string> $labels
     * @param array<int, list<array{position: int, thing: int, optional: bool, unused: bool}>> $demand
     */
    private function optionsForm(BuildOrder $order, array $labels, array $demand): string
    {
        $html = '';

        foreach ($order->assemblies as $assembly) {
            $all  = $demand[$assembly->id] ?? [];
            $rows = array_filter($all, static fn (array $row): bool => $row['position'] > 0);
            $miss = static fn (array $row): string => $row['missing'] > 0.0 ? ' · ' . sprintf(__('fehlt %s', 'taxmod-manufacturing'), rtrim(rtrim(number_format($row['missing'], 2, ',', ''), '0'), ',')) : '';
            // His word 2026-11-15 «die platine wird auch nicht aufgelistet»: the bare board heads the list; it is always built.
            $formId = 'taxmod-mf-options-' . $assembly->id;
            // Architecture 16.11.: «Alle auswählen / abwählen» is the tick in the table head, not two buttons.
            $list   = isset($all[0]) && $all[0]['position'] === 0
                ? '<tr><th scope="row" class="check-column"><input type="checkbox" checked disabled></th><td>' . esc_html(sprintf(__('Platine: %s', 'taxmod-manufacturing'), $labels[$all[0]['thing']] ?? '#' . $all[0]['thing'])) . '</td><td>' . esc_html(ltrim($miss($all[0]), ' ·')) . '</td><td></td></tr>'
                : '';

            foreach ($rows as $row) {
                $list .= '<tr><th scope="row" class="check-column"><input type="checkbox" name="build[]" form="' . esc_attr($formId) . '" value="' . esc_attr((string) $row['position']) . '"' . checked(! $row['unused'], true, false) . '>'
                    . '<input type="hidden" name="optional[]" form="' . esc_attr($formId) . '" value="' . esc_attr((string) $row['position']) . '"></th>'
                    . '<td>' . esc_html($labels[$row['thing']] ?? '#' . $row['thing']) . '</td><td>' . esc_html($row['unused'] ? '' : ltrim($miss($row), ' ·')) . '</td><td></td></tr>';
            }

            $html .= '<form method="post" id="' . esc_attr($formId) . '" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
                . $this->hidden(['action' => self::ACTION, 'do' => 'options', 'order' => (string) $order->id, 'assembly' => (string) $assembly->id, 'tab' => $this->tab()])
                . ListPage::toolbar([$this->saveButton()]) . '</form>'
                . '<p><strong>' . esc_html($labels[$assembly->revision] ?? '#' . $assembly->revision) . '</strong></p>'
                . ListPage::table([[__('Position', 'taxmod-manufacturing')], [__('Fehlt', 'taxmod-manufacturing')]], $list);
        }

        return $html;
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
                    . '<button type="submit" class="button button-small" form="taxmod-mf-step-' . esc_attr((string) $order->id) . '" name="readd" value="' . esc_attr($assembly->id . ':' . $row['position']) . '">'
                    . esc_html__('wieder hinzufügen', 'taxmod-manufacturing') . '</button>') . '</li>';
            }
        }

        return $items === '' ? '' : '<ul>' . $items . '</ul>';
    }

    /**
     * D-1376: the order's fields through the form renderer. Count and the board's revision change while it is «Angelegt»
     * (one revision per order for now), the box while it is open; the rest is shown.
     *
     * @param array<int, string> $labels
     */
    private function headForm(BuildOrder $order, array $labels, int $missing): string
    {
        $planned  = $order->step === 'order_planned';
        $id       = 'taxmod-mf-head-' . $order->id;
        $revision = count($order->assemblies) === 1 ? $order->assemblies[0]->revision : 0;
        $named    = $this->service->labelsOf(array_column($order->assemblies, 'revision'));
        $boards   = $this->service->boardsOfRevisions(array_column($order->assemblies, 'revision'));
        $boardOf  = $this->service->labelsOf(array_values($boards));
        $board    = static fn (int $rev): string => trim(($boardOf[$boards[$rev] ?? 0] ?? '') . ' · ' . ($named[$rev] ?? '#' . $rev), ' ·');
        $text     = static fn (string $value): string => '<strong>' . esc_html($value) . '</strong>';

        if ($planned && $revision > 0) {
            $options = '';

            foreach ($this->service->siblingRevisions($revision) as $rev => $label) {
                $options .= '<option value="' . esc_attr((string) $rev) . '"' . selected($rev, $revision, false) . '>' . esc_html(($boardOf[$boards[$revision] ?? 0] ?? '') . ' · ' . $label) . '</option>';
            }

            $boardControl = '<select name="revision" id="' . esc_attr($id) . '-revision">' . $options . '</select>';
        } else {
            $boardControl = $text(implode(', ', array_map(static fn (Assembly $a): string => $board($a->revision), $order->assemblies)));
        }

        $boxes = '<option value="0">' . esc_html__('— kein Gebinde —', 'taxmod-manufacturing') . '</option>';

        foreach ($this->service->boxesOf($order->project) as $box => $label) {
            $boxes .= '<option value="' . esc_attr((string) $box) . '"' . selected($box, $order->box, false) . '>' . esc_html($label) . '</option>';
        }

        $rows = [
            ['label' => __('Projekt', 'taxmod-manufacturing'), 'control' => $text($labels[$order->project] ?? '#' . $order->project)],
            ['label' => _n('Platine', 'Platinen', count($order->assemblies), 'taxmod-manufacturing'), 'control' => $boardControl, 'for' => $planned && $revision > 0 ? $id . '-revision' : ''],
            ['label' => __('Stück', 'taxmod-manufacturing'), 'for' => $order->isClosed() ? '' : $id . '-count', 'control' => $order->isClosed()
                ? $text((string) $order->count)
                : '<input type="number" class="small-text" min="' . esc_attr((string) max([1, ...array_map(static fn ($a): int => $a->done, $order->assemblies)])) . '" step="1" name="count" id="' . esc_attr($id) . '-count" value="' . esc_attr((string) $order->count) . '">',
                'hint' => $order->step === 'order_planned' ? '' : __('Weniger Stück geben überzählige Reservierungen frei; was schon im Gebinde liegt, bleibt dort bis «Box leer räumen».', 'taxmod-manufacturing')],
            ['label' => __('Gebinde', 'taxmod-manufacturing'), 'for' => $order->isClosed() ? '' : $id . '-box', 'control' => $order->isClosed()
                ? $text($order->box === null ? '—' : ($this->service->labelsOf([$order->box])[$order->box] ?? '#' . $order->box))
                : '<select name="box" id="' . esc_attr($id) . '-box">' . $boxes . '</select>',
                'hint' => __('Was im Gebinde und darunter liegt, zählt als vorhanden. Projekt-Gebinde legst du im Lager an.', 'taxmod-manufacturing')],
            ['label' => __('Fehlende Pos.', 'taxmod-manufacturing'), 'control' => $text($order->box === null ? '—' : (string) $missing)],
        ];

        if ($this->service->structure()->hasField('order_due')) {
            $rows[] = ['label' => __('Wunschtermin', 'taxmod-manufacturing'), 'for' => $order->isClosed() ? '' : $id . '-due', 'control' => $order->isClosed()
                ? $text($order->due ?? '—')
                : '<input type="date" name="due" id="' . esc_attr($id) . '-due" value="' . esc_attr((string) $order->due) . '">',
                'hint' => __('Optional. Plant nichts, steht nur am Auftrag.', 'taxmod-manufacturing')];
        }

        $rows = [...$rows,
            ['label' => __('Begonnen', 'taxmod-manufacturing'), 'control' => $text($order->started ?? '—')],
            ['label' => __('Fertig', 'taxmod-manufacturing'), 'control' => $text($order->finished ?? '—')],
        ];

        if ($order->shortageReason !== '') {
            $rows[] = ['label' => __('Grund Fehlteile', 'taxmod-manufacturing'), 'control' => $text($order->shortageReason)];
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'do' => 'head', 'order' => (string) $order->id, 'tab' => $this->tab()])
            . ListPage::form($rows, $order->isClosed() ? '' : $this->saveButton()) . '</form>';
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

    /** admin-post: the page's acts live in {@see OrderActions} (architecture 16.11.: the page draws, the handler acts). */
    public function handle(string $action): void
    {
        (new OrderActions($this, $this->service))->handle($action);
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
                . ($order->box !== null && Demand::missingRows([$rows]) > 0 ? ' · <strong class="taxmod-mf-status taxmod-mf-status-warn">' . esc_html(sprintf(__('%d Positionen fehlen', 'taxmod-manufacturing'), Demand::missingRows([$rows]))) . '</strong>' : '') . '</li>';
        }

        $details = '<ul class="taxmod-mf-details">' . $details . '</ul><p><a class="button" href="' . esc_url($this->url(['auftrag' => $order->id, 'tab' => $this->tab()])) . '">' . esc_html__('Öffnen', 'taxmod-manufacturing') . '</a></p>';

        return ListPage::row(self::FORM, (string) $order->id, $cells, $this->rowButtons($order, $order->box === null ? 0 : Demand::missingRows($demand)), $details);
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
        $body   = [];
        $form   = 'taxmod-mf-a' . $assembly->id;
        $scrap  = $boxed && ! $order->isClosed() && $this->service->canScrap();

        $bulk   = 'taxmod-mf-fitbulk-' . $assembly->id;
        $closed = $order->isClosed();

        foreach ($rows as $row) {
            $position = (string) $row['position'];
            $fit      = $row['position'] === 0 || $row['unused'] || $closed
                ? esc_html($count($row['boards'] * $row['count']))
                : '<input type="number" class="small-text" name="fitted[' . esc_attr($position) . ']" form="' . esc_attr($form) . '" min="0" max="' . esc_attr($count($row['needed'])) . '" step="' . esc_attr($count($row['count'])) . '" value="' . esc_attr($count($row['boards'] * $row['count'])) . '">';
            // D-1403: what acts on rows is a bulk choice («Nicht verwenden», «Wieder verwenden»); the save keeps what is left out.
            $kept     = $row['unused'] ? '<input type="hidden" name="unused[]" form="' . esc_attr($form) . '" value="' . esc_attr($position) . '">' : '';
            $tick     = $row['position'] === 0 || $closed ? '' : '<input type="checkbox" name="selected[]" form="' . esc_attr($bulk) . '" value="' . esc_attr($position) . '">';
            $cells    = [
                esc_html($row['position'] === 0 ? __('Platine', 'taxmod-manufacturing') : (string) ($refs[$row['position']] ?? '')),
                $this->part($row['thing'], $labels, $order),
                esc_html($count($row['needed'])),
                '<span class="taxmod-mf-nowrap">' . $fit . '</span>',
                esc_html($boxed ? $count($row['present']) : '—'),
                $boxed && $row['missing'] > 0.0 ? '<span class="taxmod-mf-status taxmod-mf-status-warn">' . esc_html($count($row['missing'])) . '</span>' : '',
                $row['unused'] ? '<span class="taxmod-mf-status taxmod-mf-status-info">' . esc_html__('nicht verwendet', 'taxmod-manufacturing') . '</span>' . $kept : ($row['optional'] ? esc_html__('optional', 'taxmod-manufacturing') : ''),
            ];

            if ($scrap) {
                $cells[] = '<input type="number" class="small-text" name="scrap[' . esc_attr($position) . ']" form="' . esc_attr($form) . '" min="0" step="1" value="" aria-label="' . esc_attr__('Ausschuss', 'taxmod-manufacturing') . '">';
            }

            $body[] = '<tr' . ($row['unused'] ? ' class="taxmod-mf-unused"' : '') . '><th scope="row" class="check-column">' . $tick . '</th><td>' . implode('</td><td>', $cells) . '</td><td></td></tr>';
        }

        $table = ListPage::table(array_merge([
            [__('Referenz', 'taxmod-manufacturing')], [__('Teil', 'taxmod-manufacturing')], [__('Bedarf (Teile)', 'taxmod-manufacturing')],
            [__('Bestückt (Teile)', 'taxmod-manufacturing')], [__('Im Gebinde', 'taxmod-manufacturing')], [__('Fehlt', 'taxmod-manufacturing')],
            [__('Option', 'taxmod-manufacturing')],
        ], $scrap ? [[__('Ausschuss', 'taxmod-manufacturing')]] : []), implode('', $body));

        $fittedBoards = Demand::boardsFitted($rows);
        // D-1403: one bar — the choices on ticked rows, the save of this board in the same row.
        $bar = $closed ? '' : ListPage::bulkBar($bulk, self::NONCE, [
            self::BULK_FIT_FULL => __('Voll bestückt (alle Stück)', 'taxmod-manufacturing'),
            self::BULK_FIT_NONE => __('Bestückt auf 0', 'taxmod-manufacturing'),
            self::BULK_UNUSE    => __('Nicht verwenden', 'taxmod-manufacturing'),
            self::BULK_USE      => __('Wieder verwenden', 'taxmod-manufacturing'),
        ], sprintf(_n('%d Position', '%d Positionen', count($rows), 'taxmod-manufacturing'), count($rows)), '<div class="alignleft actions">' . $this->saveButton($form) . '</div>', false)
            . $this->hidden(['order' => (string) $order->id, 'assembly' => (string) $assembly->id, 'tab' => $this->tab()], $bulk);
        // D-1304, D-1342: the test is a report at the finished board's lot on the place «Test», no longer a count here; «Fertig» means built.
        $counts = $this->service->structure()->hasField('assembly_done')
            ? '<label title="' . esc_attr__('Fertig sind voll bestückte Platinen. Erst jetzt werden ihre Teile verbraucht; die Platine kommt ungeprüft auf den Platz «Test».', 'taxmod-manufacturing') . '">' . esc_html__('Fertig', 'taxmod-manufacturing') . ' <input type="number" class="small-text" name="done" form="' . esc_attr($form) . '" min="0" max="' . esc_attr((string) $pieces) . '" value="' . esc_attr((string) $assembly->done) . '"></label>'
            : '';

        return '<form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-mf-assembly">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . $this->hidden(['action' => self::ACTION, 'do' => 'assembly', 'order' => (string) $order->id, 'assembly' => (string) $assembly->id, 'tab' => $this->tab()])
            . '<h3>' . esc_html($labels[$assembly->revision] ?? '#' . $assembly->revision)
            . ' · ' . esc_html(sprintf(__('%1$d von %2$d bestückt', 'taxmod-manufacturing'), $fittedBoards, $pieces)) . '</h3>'
            . '</form>' . $bar
            . '<p class="description">' . esc_html__('So geht es: «Bestückt» je Position eintragen (oder Zeilen anhaken → «Voll bestückt»), dann «Fertig» auf die Zahl fertiger Platinen setzen und 💾 speichern. Fertig geht nur bis zur Zahl voll bestückter Platinen; beim ersten Fertig braucht es die Hardware-Sparte.', 'taxmod-manufacturing') . '</p>'
            . '<div class="taxmod-mf-counts">'
            . $counts . $this->sparteChooser($assembly, $form)
            . ($scrap ? '<label>' . esc_html__('Grund Ausschuss (optional)', 'taxmod-manufacturing') . ' <input type="text" name="scrap_reason" form="' . esc_attr($form) . '" list="taxmod-mf-scrap-reasons"></label><datalist id="taxmod-mf-scrap-reasons"><option value="' . esc_attr__('heruntergefallen', 'taxmod-manufacturing') . '"><option value="' . esc_attr__('defekt', 'taxmod-manufacturing') . '"><option value="' . esc_attr__('falsch bestückt', 'taxmod-manufacturing') . '"></datalist>' : '')
            . ($this->service->structure()->hasField('assembly_note') ? '<label>' . esc_html__('Hinweis', 'taxmod-manufacturing') . ' <input type="text" class="regular-text" name="note" form="' . esc_attr($form) . '" value="' . esc_attr($assembly->note) . '"></label>' : '')
            . '</div>'
            . $table;
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
            . '<label class="taxmod-dialog-field taxmod-mf-field">' . esc_html__('Stückzahl', 'taxmod-manufacturing') . '<br><input type="number" name="count" min="1" step="1" value="1" required></label>';

        if (method_exists(ListPage::class, 'shownBy')) {
            // D-1311: the boards of the chosen project show in the dialog itself; the server still takes only that project's.
            foreach (array_intersect_key($this->boards, $projects) as $project => $boards) {
                $body .= ListPage::shownBy('project', (string) $project, '<fieldset><legend>' . esc_html__('Platinen', 'taxmod-manufacturing') . '</legend>' . $this->boardBoxes($boards) . '</fieldset>');
            }

            $body .= $this->hidden(['choosing' => '1']);
        } else {
            $body .= '<p>' . esc_html__('Im nächsten Schritt wählst du, welche Platinen des Projekts gebaut werden.', 'taxmod-manufacturing') . '</p>';
        }

        return '<form method="post" id="' . esc_attr($formId) . '" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
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
            $boxes .= '<p><label><input type="checkbox" name="boards[]" value="' . esc_attr((string) $revision) . '"' . ($only ? ' checked' : '') . '> ' . esc_html($label) . '</label></p>';
        }

        return $boxes;
    }

    private function dialogForm(string $do, BuildOrder $order, string $opener, string $title, string $body): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="taxmod-mf-inline">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'order' => (string) $order->id, 'tab' => $this->tab(), 'view' => absint($_GET['auftrag'] ?? 0) === $order->id ? 'detail' : ''])
            . ListPage::dialogAction('taxmod-mf-' . $do . '-' . $order->id, $opener, $title, '<p>' . $body . '</p>', 'do', $do, $opener)
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
            'saved'     => __('Platine gespeichert.', 'taxmod-manufacturing'),
            'saved_booked'   => __('Platine gespeichert, Verbrauch im Lager gebucht.', 'taxmod-manufacturing'),
            'saved_unbooked' => __('Platine gespeichert. Der Verbrauch ist noch nicht gebucht: das Lager bietet die Buchung noch nicht an.', 'taxmod-manufacturing'),
            'box'       => __('Gebinde gespeichert.', 'taxmod-manufacturing'),
            'head'      => __('Auftrag gespeichert.', 'taxmod-manufacturing'),
            'options'   => __('Aufbau-Optionen gespeichert.', 'taxmod-manufacturing'),
            'readded'   => __('Position wieder hinzugefügt.', 'taxmod-manufacturing'),
            'stepped_back' => __('Einen Schritt zurück.', 'taxmod-manufacturing'),
            'reordered' => __('Fehlendes als Bedarf im Einkauf angelegt.', 'taxmod-manufacturing'),
            'nothing_missing' => __('Es fehlt nichts, was nirgends am Lager liegt.', 'taxmod-manufacturing'),
            'picked'    => __('Abgehakte Teile ins Gebinde umgebucht.', 'taxmod-manufacturing'),
            'nothing_picked' => __('Nichts abgehakt, nichts gebucht.', 'taxmod-manufacturing'),
            'closed'    => __('Auftrag abgeschlossen. «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'),
            'cancelled' => __('Abgebrochen. «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'),
            'reopened'  => __('Wieder aufgenommen.', 'taxmod-manufacturing'),
            'staging'   => __('In Bereitstellung.', 'taxmod-manufacturing'),
            'reserved'  => __('Abgehakte Teile für den Auftrag reserviert.', 'taxmod-manufacturing'),
            'changed'   => __('Reservierung geändert.', 'taxmod-manufacturing'),
            'released'  => __('Reservierung aufgehoben (Storno im Lager).', 'taxmod-manufacturing'),
            'assigned'  => __('Der Position zugewiesen und für den Auftrag reserviert.', 'taxmod-manufacturing'),
            'cleared'   => __('Gebinde leer geräumt, alles zurück an seinen Platz.', 'taxmod-manufacturing'),
            'building'  => __('In Fertigung.', 'taxmod-manufacturing'),
        ][sanitize_key((string) ($_GET['msg'] ?? ''))] ?? '';

        return $text === '' ? '' : '<div class="notice notice-success"><p>' . esc_html($text) . '</p></div>';
    }

    public function message(ManufacturingError $error): string
    {
        return match ($error->reason) {
            'not_configured'           => sprintf(__('Im Modell fehlt %s.', 'taxmod-manufacturing'), $error->detail),
            'shortage_without_reason'  => __('Es fehlen noch Teile: bitte einen Grund angeben.', 'taxmod-manufacturing'),
            'no_board_chosen'          => __('Keine Platine gewählt.', 'taxmod-manufacturing'),
            'project_without_boards'   => __('Das Projekt hat keine Platine mit Revision.', 'taxmod-manufacturing'),
            'count_not_positive'       => __('Die Stückzahl muss eine ganze Zahl ab 1 sein.', 'taxmod-manufacturing'),
            /* translators: %s: the parts the box lacks, «2 × name, …» */
            'short_at_finish'          => sprintf(__('Im Gebinde fehlt für «Fertig»: %s. Erst umbuchen oder «Aus Bestand wählen», dann fertig melden.', 'taxmod-manufacturing'), $error->detail),
            'due_not_date'             => __('Der Wunschtermin ist kein gültiges Datum.', 'taxmod-manufacturing'),
            'count_below_done'         => __('Die Stückzahl darf nicht unter die fertigen Platinen fallen.', 'taxmod-manufacturing'),
            'unknown_order'            => __('Diesen Auftrag gibt es nicht.', 'taxmod-manufacturing'),
            'assembly_not_of_order'    => __('Diese Platine gehört nicht zum Auftrag.', 'taxmod-manufacturing'),
            'boards_out_of_range'      => __('Eine Platinenzahl liegt außerhalb von 0 bis zur Stückzahl.', 'taxmod-manufacturing'),
            'done_beyond_fitted'       => __('Fertig können höchstens so viele Platinen sein, wie voll bestückt sind.', 'taxmod-manufacturing'),
            'sparte_needed'            => __('Für die erste fertige Platine dieser Revision bitte die Hardware-Sparte wählen.', 'taxmod-manufacturing'),
            'stock_refused'            => sprintf(__('Das Lager hat die Buchung abgelehnt, nichts gespeichert: %s', 'taxmod-manufacturing'), $error->detail),
            'assemblies_not_done'      => __('Erst wenn alle Platinen fertig sind, lässt sich der Auftrag abschließen.', 'taxmod-manufacturing'),
            'position_not_on_revision' => __('Nur optionale Positionen der Stückliste lassen sich weglassen.', 'taxmod-manufacturing'),
            'head_fixed'               => __('Ein abgeschlossener Auftrag behält seine Stückzahl.', 'taxmod-manufacturing'),
            'readd_after_use'          => __('Von dieser Platine ist schon etwas verbraucht; die Position kommt nur noch über eine Nachbuchung dazu.', 'taxmod-manufacturing'),
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
            'order_planned'   => __('geplant', 'taxmod-manufacturing'),
            'order_staging'   => __('Bereitstellung', 'taxmod-manufacturing'),
            'order_building'  => __('in Bau', 'taxmod-manufacturing'),
            'order_done'      => __('fertig', 'taxmod-manufacturing'),
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
            'order_planned', 'order_staging', 'order_building' => $order->step === $tab,
            'all'                => true,
            default     => ! $order->isClosed(),
        };
    }

    /** A refusal (capability, nonce) as a message on the page instead of a bare error page (D-1254). */
    public function refuse(string $message): never
    {
        if (method_exists(ListPage::class, 'fail')) {
            ListPage::fail($message, $this->url(['tab' => sanitize_key((string) ($_POST['tab'] ?? ''))]));
        }

        wp_die(esc_html($message), '', ['response' => 403, 'back_link' => true]);
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
