<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Demand;
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
    public const SLUG = 'taxmod-fertigung';

    public const ACTION = 'taxmod_manufacturing';

    public const BULK_CANCEL = 'taxmod_manufacturing_cancel';

    public const BULK_REOPEN = 'taxmod_manufacturing_reopen';

    /** @var list<string> the admin-post actions this page answers */
    public const ACTIONS = [self::ACTION, self::BULK_CANCEL, self::BULK_REOPEN];

    private const NONCE = 'taxmod_manufacturing';

    private const FORM = 'taxmod-mf-bulk';

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
        $tabs  = [
            ['open', __('Offen', 'taxmod-manufacturing'), count(array_filter($orders, static fn (BuildOrder $o): bool => ! $o->isClosed()))],
            ['done', __('Fertig', 'taxmod-manufacturing'), count(array_filter($orders, static fn (BuildOrder $o): bool => $o->step === 'order_done'))],
            ['cancelled', __('Abgebrochen', 'taxmod-manufacturing'), count(array_filter($orders, static fn (BuildOrder $o): bool => $o->step === 'order_cancelled'))],
            ['all', __('Alle', 'taxmod-manufacturing'), count($orders)],
        ];
        $shown = array_values(array_filter($orders, fn (BuildOrder $o): bool => $this->inTab($o, $tab)));

        $html .= '<div style="display:flex;justify-content:flex-end;margin:8px 0">' . $this->createAction() . '</div>'
            . ListPage::tabs($tabs, $tab, fn (string $slug): string => $this->url(['tab' => $slug]))
            . ListPage::bulkBar(self::FORM, self::NONCE, [self::BULK_CANCEL => __('Abbrechen', 'taxmod-manufacturing'), self::BULK_REOPEN => __('Wieder aufnehmen', 'taxmod-manufacturing')],
                sprintf(_n('%d Auftrag', '%d Aufträge', count($shown), 'taxmod-manufacturing'), count($shown)));

        [$lists, $labels] = $this->context($shown);
        $rows             = '';

        foreach ($shown as $order) {
            $rows .= $this->row($order, $labels, $lists, Demand::of($order->assemblies, $lists, $order->count, $this->service->presentIn($order->box)));
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
            [__('Fehlt', 'taxmod-manufacturing'), '6em'],
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
        [$lists, $labels] = $this->context([$order]);
        $demand           = Demand::of($order->assemblies, $lists, $order->count, $this->service->presentIn($order->box));
        $facts            = [
            __('Projekt', 'taxmod-manufacturing')  => esc_html($labels[$order->project] ?? '#' . $order->project),
            __('Stück', 'taxmod-manufacturing')    => esc_html((string) $order->count),
            __('Status', 'taxmod-manufacturing')   => esc_html($this->orderStepLabel($order->step)),
            __('Fehlt', 'taxmod-manufacturing')    => esc_html($order->box === null ? '—' : (string) Demand::missingRows($demand)),
            __('Begonnen', 'taxmod-manufacturing') => esc_html($order->started ?? '—'),
            __('Fertig', 'taxmod-manufacturing')   => esc_html($order->finished ?? '—'),
        ];
        $head = '';

        foreach ($facts as $label => $value) {
            $head .= '<div><span class="description">' . esc_html($label) . '</span><br><strong>' . $value . '</strong></div>';
        }

        $html = $back . '<div style="display:flex;align-items:flex-start;gap:1em"><h2 style="flex:1;margin:.25em 0">' . esc_html($order->label) . '</h2>'
            . '<div style="display:flex;gap:.5em">' . $this->rowButtons($order) . '</div></div>'
            . '<div style="display:flex;gap:2.5em;flex-wrap:wrap;margin:.5em 0 1em">' . $head . '</div>'
            . $this->boxForm($order);

        foreach ($order->assemblies as $assembly) {
            $html .= $this->assemblyForm($order, $assembly, $labels, $lists[$assembly->revision] ?? [], $demand[$assembly->id] ?? []);
        }

        return $html;
    }

    /**
     * Parts lists and labels for the given orders, read once (CD-7).
     *
     * @param list<BuildOrder> $orders
     * @return array{0: array<int, list<array{id: int, thing: int, count: float, fitting: string, ref: string}>>, 1: array<int, string>}
     */
    private function context(array $orders): array
    {
        $revisions = [];

        foreach ($orders as $order) {
            foreach ($order->assemblies as $assembly) {
                $revisions[$assembly->revision] = true;
            }
        }

        $lists  = $this->service->partsLists(array_keys($revisions));
        $things = [];

        foreach ($lists as $positions) {
            foreach ($positions as $position) {
                $things[] = $position['thing'];
            }
        }

        $labels = $this->service->labelsOf(array_values(array_filter(array_unique([...array_keys($revisions), ...$things, ...array_map(static fn (BuildOrder $o): int => $o->project, $orders), ...array_filter(array_map(static fn (BuildOrder $o): ?int => $o->box, $orders))]))));

        return [$lists, $labels];
    }

    /** admin-post: capability → nonce → validate → sanitize → act → back to the page (CD-5). */
    public function handle(string $action): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Keine Berechtigung.', 'taxmod-manufacturing'), '', ['response' => 403]);
        }

        check_admin_referer(self::NONCE);

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
        $label = $this->service->labelsOf([$project])[$project] ?? '#' . $project;
        $id    = $this->service->create($project, $count, null, sprintf(__('Fertigung %1$s, %2$s', 'taxmod-manufacturing'), $label, current_time('Y-m-d')));

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

        $booked       = $this->service->updateAssembly($order, absint($_POST['assembly'] ?? 0), $fitted, $unused, $number('tested'), $number('done'), $note, $today);
        $back['auftrag'] = $order;

        return ['booked' => 'saved_booked', 'unbooked' => 'saved_unbooked'][$booked] ?? 'saved';
    }

    /** @param array<string, string|int> $back */
    private function box(int $order, array &$back): string
    {
        $this->service->setBox($order, absint($_POST['box'] ?? 0));
        $back['auftrag'] = $order;

        return 'box';
    }

    /**
     * @param 'order_done'|'order_cancelled'|'reopen' $to
     * @param array<string, string|int> $back
     */
    private function step(int $order, string $to, string $today, array &$back): string
    {
        $this->service->setStep($order, $to, $today);
        if (sanitize_key((string) ($_POST['view'] ?? '')) === 'detail') {
            $back['auftrag'] = $order;
        }

        return ['order_done' => 'closed', 'order_cancelled' => 'cancelled', 'reopen' => 'reopened'][$to];
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
            esc_html($this->orderStepLabel($order->step)),
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

        return ListPage::row(self::FORM, (string) $order->id, $cells, $this->rowButtons($order), $details);
    }

    /** The order's box: everything lying on it and in it counts as there (his word 2026-11-04). */
    private function boxForm(BuildOrder $order): string
    {
        $options = '<option value="0">' . esc_html__('— kein Gebinde —', 'taxmod-manufacturing') . '</option>';

        foreach ($this->service->boxesOf($order->project) as $id => $label) {
            $options .= '<option value="' . esc_attr((string) $id) . '"' . selected($id, $order->box, false) . '>' . esc_html($label) . '</option>';
        }

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;align-items:center;gap:1em;padding:.75em 0">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . $this->hidden(['action' => self::ACTION, 'do' => 'box', 'order' => (string) $order->id, 'tab' => $this->tab()])
            . '<label>' . esc_html__('Gebinde', 'taxmod-manufacturing') . ' <select name="box">' . $options . '</select></label>'
            . '<span class="description" style="flex:1">' . esc_html__('Was im Gebinde und darunter liegt, zählt als vorhanden. Projekt-Gebinde legst du im Lager an.', 'taxmod-manufacturing') . '</span>'
            . '<button type="submit" class="button">' . esc_html__('Speichern', 'taxmod-manufacturing') . '</button></form>';
    }

    private function rowButtons(BuildOrder $order): string
    {
        $buttons = '';

        if (! $order->isClosed() && $order->isFinished()) {
            $buttons .= $this->dialogForm('done', $order, __('Abschließen', 'taxmod-manufacturing'), __('Auftrag abschließen?', 'taxmod-manufacturing'),
                esc_html__('Alle Platinen aller Baugruppen sind fertig. Der Auftrag wird als fertig markiert; «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'));
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
                . '<td>' . esc_html($labels[$row['thing']] ?? '#' . $row['thing']) . '</td>'
                . '<td style="text-align:right">' . esc_html($count($row['needed'])) . '</td>'
                . '<td style="white-space:nowrap">' . $fit . '</td>'
                . '<td style="text-align:right">' . esc_html($boxed ? $count($row['present']) : '—') . '</td>'
                . '<td style="text-align:right">' . ($boxed && $row['missing'] > 0.0 ? '<strong style="color:#b32d2e">' . esc_html($count($row['missing'])) . '</strong>' : '') . '</td>'
                . '<td>' . $use . '</td></tr>';
        }

        $table = '<table class="widefat striped" style="margin-top:.5em"><thead><tr>'
            . '<th>' . esc_html__('Referenz', 'taxmod-manufacturing') . '</th><th>' . esc_html__('Teil', 'taxmod-manufacturing') . '</th>'
            . '<th style="text-align:right">' . esc_html__('Bedarf', 'taxmod-manufacturing') . '</th><th>' . esc_html__('Bestückt (Teile)', 'taxmod-manufacturing') . '</th>'
            . '<th style="text-align:right">' . esc_html__('Im Gebinde', 'taxmod-manufacturing') . '</th><th style="text-align:right">' . esc_html__('Fehlt', 'taxmod-manufacturing') . '</th>'
            . '<th>' . esc_html__('Option', 'taxmod-manufacturing') . '</th></tr></thead><tbody>' . $body . '</tbody></table>';

        $fittedBoards = Demand::boardsFitted($rows);
        // «alle auf n Platinen»: n × the parts per board in every field of this form.
        $fill = '<input type="number" class="small-text" min="0" max="' . esc_attr((string) $pieces) . '" value="' . esc_attr((string) $pieces) . '" aria-label="' . esc_attr__('Anzahl Platinen', 'taxmod-manufacturing') . '"> '
            . '<button type="button" class="button" onclick="var n=this.previousElementSibling.value;this.closest(\'form\').querySelectorAll(\'.taxmod-mf-fit\').forEach(function(i){i.value=n*parseFloat(i.step)})">' . esc_html__('Alle auf n Platinen', 'taxmod-manufacturing') . '</button>';
        $counts = $this->service->structure()->hasField('assembly_tested')
            ? '<label>' . esc_html__('Geprüft', 'taxmod-manufacturing') . ' <input type="number" class="small-text" name="tested" min="0" max="' . esc_attr((string) $pieces) . '" value="' . esc_attr((string) $assembly->tested) . '"></label>'
                . '<label>' . esc_html__('Fertig', 'taxmod-manufacturing') . ' <input type="number" class="small-text" name="done" min="0" max="' . esc_attr((string) $pieces) . '" value="' . esc_attr((string) $assembly->done) . '"></label>'
            : '';

        return '<form method="post" id="' . esc_attr($form) . '" action="' . esc_url(admin_url('admin-post.php')) . '" style="border-top:1px solid #dcdcde;padding:.75em 0">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . $this->hidden(['action' => self::ACTION, 'do' => 'assembly', 'order' => (string) $order->id, 'assembly' => (string) $assembly->id, 'tab' => $this->tab()])
            . '<div style="display:flex;align-items:center;gap:1em;flex-wrap:wrap"><strong style="flex:1">' . esc_html($labels[$assembly->revision] ?? '#' . $assembly->revision)
            . ' · ' . esc_html(sprintf(__('%1$d von %2$d bestückt', 'taxmod-manufacturing'), $fittedBoards, $pieces)) . '</strong>'
            . $fill . $counts
            . ($this->service->structure()->hasField('assembly_note') ? '<label>' . esc_html__('Hinweis', 'taxmod-manufacturing') . ' <input type="text" class="regular-text" name="note" value="' . esc_attr($assembly->note) . '"></label>' : '')
            . '<button type="submit" class="button button-primary">' . esc_html__('Speichern', 'taxmod-manufacturing') . '</button></div>'
            . $table . '</form>';
    }

    private function createAction(): string
    {
        $options = '<option value="">' . esc_html__('— Projekt wählen —', 'taxmod-manufacturing') . '</option>';

        foreach ($this->service->projects() as $id => $label) {
            $options .= '<option value="' . esc_attr((string) $id) . '">' . esc_html($label) . '</option>';
        }

        $body = '<label class="taxmod-dialog-field" style="display:block;margin-bottom:.5em">' . esc_html__('Projekt', 'taxmod-manufacturing') . '<br><select name="project" required>' . $options . '</select></label>'
            . '<label class="taxmod-dialog-field" style="display:block">' . esc_html__('Stückzahl', 'taxmod-manufacturing') . '<br><input type="number" name="count" min="1" step="1" value="1" required></label>'
            . '<p>' . esc_html__('Je Platine des Projekts entsteht eine Baugruppe mit ihrer neuesten Revision, Status «offen».', 'taxmod-manufacturing') . '</p>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field(self::NONCE, '_wpnonce', true, false)
            . $this->hidden(['action' => self::ACTION, 'tab' => $this->tab()])
            . ListPage::dialogAction('taxmod-mf-new', __('Neuer Auftrag', 'taxmod-manufacturing'), __('Neuer Fertigungsauftrag', 'taxmod-manufacturing'), $body, 'do', 'create', __('Anlegen', 'taxmod-manufacturing'))
            . '</form>';
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
        return class_exists(ListPage::class)
            ? ListPage::heading(__('Aufträge', 'taxmod-manufacturing'), (string) TAXMOD_MANUFACTURING_VERSION, __('Fertigung', 'taxmod-manufacturing'))
            : '<h1>' . esc_html__('Fertigung › Aufträge', 'taxmod-manufacturing') . '</h1>';
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
            'closed'    => __('Auftrag abgeschlossen. «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'),
            'cancelled' => __('Abgebrochen. «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'),
            'reopened'  => __('Wieder aufgenommen.', 'taxmod-manufacturing'),
        ][sanitize_key((string) ($_GET['msg'] ?? ''))] ?? '';

        return $text === '' ? '' : '<div class="notice notice-success"><p>' . esc_html($text) . '</p></div>';
    }

    private function message(ManufacturingError $error): string
    {
        return match ($error->reason) {
            'not_configured'           => sprintf(__('Im Modell fehlt %s.', 'taxmod-manufacturing'), $error->detail),
            'project_without_boards'   => __('Das Projekt hat keine Platine mit Revision.', 'taxmod-manufacturing'),
            'count_not_positive'       => __('Die Stückzahl muss eine ganze Zahl ab 1 sein.', 'taxmod-manufacturing'),
            'unknown_order'            => __('Diesen Auftrag gibt es nicht.', 'taxmod-manufacturing'),
            'assembly_not_of_order'    => __('Diese Platine gehört nicht zum Auftrag.', 'taxmod-manufacturing'),
            'boards_out_of_range'      => __('Eine Platinenzahl liegt außerhalb von 0 bis zur Stückzahl.', 'taxmod-manufacturing'),
            'stock_refused'            => sprintf(__('Das Lager hat die Buchung abgelehnt, nichts gespeichert: %s', 'taxmod-manufacturing'), $error->detail),
            'assemblies_not_done'      => __('Erst wenn alle Platinen fertig sind, lässt sich der Auftrag abschließen.', 'taxmod-manufacturing'),
            'position_not_on_revision' => __('Nur optionale Positionen der Stückliste lassen sich weglassen.', 'taxmod-manufacturing'),
            'model_refused'            => sprintf(__('Das Modell hat abgelehnt: %s', 'taxmod-manufacturing'), $error->detail),
            default                    => __('Unbekannte Aktion.', 'taxmod-manufacturing'),
        };
    }

    private function orderStepLabel(string $step): string
    {
        return [
            'order_planned'   => __('geplant', 'taxmod-manufacturing'),
            'order_building'  => __('in Bau', 'taxmod-manufacturing'),
            'order_done'      => __('fertig', 'taxmod-manufacturing'),
            'order_cancelled' => __('abgebrochen', 'taxmod-manufacturing'),
        ][$step] ?? $step;
    }

    private function tab(): string
    {
        $tab = sanitize_key((string) ($_REQUEST['tab'] ?? 'open'));

        return in_array($tab, ['open', 'done', 'cancelled', 'all'], true) ? $tab : 'open';
    }

    private function inTab(BuildOrder $order, string $tab): bool
    {
        return match ($tab) {
            'done'      => $order->step === 'order_done',
            'cancelled' => $order->step === 'order_cancelled',
            'all'       => true,
            default     => ! $order->isClosed(),
        };
    }

    /** @param array<string, string|int> $args */
    private function url(array $args): string
    {
        return add_query_arg(array_filter(['page' => 'taxmod-lager-modul', 'module' => self::SLUG] + $args, static fn ($v): bool => $v !== '' && $v !== 0), admin_url('admin.php'));
    }
}
