<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\Structure;
use TaxmodManufacturing\WordPress\ManufacturingService;

/**
 * Fertigung › Aufträge (D-1142): the build orders in the shared list template (D-989), a board's step and its missing
 * positions in the row's details, every act behind a dialog (D-1063). Returns strings (CD-8).
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

        $revisions = [];

        foreach ($shown as $order) {
            foreach ($order->assemblies as $assembly) {
                $revisions[$assembly->revision] = true;
            }
        }

        $lists    = $this->service->partsLists(array_keys($revisions));
        $labels   = $this->service->labelsOf([...array_keys($revisions), ...array_map(static fn (BuildOrder $o): int => $o->project, $shown)]);
        $rows     = '';

        foreach ($shown as $order) {
            $rows .= $this->row($order, $labels, $lists);
        }

        if ($rows === '') {
            $rows = '<tr class="no-items"><td class="colspanchange" colspan="9">' . esc_html__('Keine Aufträge.', 'taxmod-manufacturing') . '</td></tr>';
        }

        return $html . ListPage::table([
            [__('Auftrag', 'taxmod-manufacturing')],
            [__('Projekt', 'taxmod-manufacturing')],
            [__('Stück', 'taxmod-manufacturing'), '5em'],
            [__('Status', 'taxmod-manufacturing'), '8em'],
            [__('Platinen fertig', 'taxmod-manufacturing'), '9em'],
            [__('Fehlende Teile', 'taxmod-manufacturing'), '9em'],
            [__('Begonnen', 'taxmod-manufacturing'), '8em'],
        ], $rows) . '</div>';
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

        $back['open'] = $id;
        $back['tab']  = 'open';

        return 'created';
    }

    /** @param array<string, string|int> $back */
    private function updateAssembly(int $order, string $today, array &$back): string
    {
        $step = sanitize_key((string) ($_POST['step'] ?? ''));
        $open = array_values(array_filter(array_map('absint', (array) ($_POST['missing'] ?? []))));

        $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash((string) $_POST['note'])) : null;

        $this->service->updateAssembly($order, absint($_POST['assembly'] ?? 0), $step, $open, $today, $note);
        $back['open'] = $order;

        return 'saved';
    }

    /**
     * @param 'order_done'|'order_cancelled'|'reopen' $to
     * @param array<string, string|int> $back
     */
    private function step(int $order, string $to, string $today, array &$back): string
    {
        $this->service->setStep($order, $to, $today);
        $back['open'] = $order;

        return ['order_done' => 'closed', 'order_cancelled' => 'cancelled', 'reopen' => 'reopened'][$to];
    }

    /**
     * @param array<int, string> $labels
     * @param array<int, list<array{id: int, part: string, count: string}>> $lists
     */
    private function row(BuildOrder $order, array $labels, array $lists): string
    {
        $done  = $order->doneCount();
        $all   = count($order->assemblies);
        $cells = [
            '<strong>' . esc_html($order->label) . '</strong>',
            esc_html($labels[$order->project] ?? '#' . $order->project),
            esc_html((string) $order->count),
            esc_html($this->orderStepLabel($order->step)),
            esc_html($done . ' / ' . $all),
            esc_html((string) $order->openPositionCount()),
            esc_html($order->started ?? '—'),
        ];

        $details = '';

        foreach ($order->assemblies as $assembly) {
            $details .= $this->assemblyForm($order, $assembly, $labels[$assembly->revision] ?? '#' . $assembly->revision, $lists[$assembly->revision] ?? []);
        }

        return ListPage::row(self::FORM, (string) $order->id, $cells, $this->rowButtons($order), $details === '' ? '<p>' . esc_html__('Keine Platinen.', 'taxmod-manufacturing') . '</p>' : $details, absint($_GET['open'] ?? 0) === $order->id);
    }

    private function rowButtons(BuildOrder $order): string
    {
        $buttons = '';

        if (! $order->isClosed() && $order->assemblies !== [] && $order->doneCount() === count($order->assemblies)) {
            $buttons .= $this->dialogForm('done', $order, __('Abschließen', 'taxmod-manufacturing'), __('Auftrag abschließen?', 'taxmod-manufacturing'),
                esc_html__('Alle Platinen sind fertig. Der Auftrag wird als fertig markiert; «Wieder aufnehmen» macht das rückgängig.', 'taxmod-manufacturing'));
        }

        if ($order->isClosed()) {
            return $buttons . $this->dialogForm('reopen', $order, __('Wieder aufnehmen', 'taxmod-manufacturing'), __('Auftrag wieder aufnehmen?', 'taxmod-manufacturing'),
                esc_html__('Der Auftrag kommt zurück in «Offen», mit dem Stand seiner Platinen.', 'taxmod-manufacturing'));
        }

        return $buttons . $this->dialogForm('cancel', $order, __('Abbrechen', 'taxmod-manufacturing'), __('Auftrag abbrechen?', 'taxmod-manufacturing'),
            esc_html__('Nichts wird gelöscht; «Wieder aufnehmen» holt ihn zurück.', 'taxmod-manufacturing'));
    }

    /** @param list<array{id: int, part: string, count: string}> $positions */
    private function assemblyForm(BuildOrder $order, Assembly $assembly, string $revision, array $positions): string
    {
        $options = '';

        foreach (Structure::ASSEMBLY_STEPS as $step) {
            $options .= '<option value="' . esc_attr($step) . '"' . selected($step, $assembly->step, false) . '>' . esc_html($this->assemblyStepLabel($step)) . '</option>';
        }

        $boxes = '';

        foreach ($positions as $position) {
            $boxes .= '<label style="display:block"><input type="checkbox" name="missing[]" value="' . esc_attr((string) $position['id']) . '"' . checked(in_array($position['id'], $assembly->open, true), true, false) . '> '
                . esc_html($position['count'] . ' × ' . $position['part']) . '</label>';
        }

        $boxes = $boxes === '' ? '<p>' . esc_html__('Die Revision hat keine Stückliste.', 'taxmod-manufacturing') . '</p>'
            : '<details' . ($assembly->open === [] ? '' : ' open') . '><summary>' . esc_html(sprintf(__('Fehlende Positionen (%1$d von %2$d)', 'taxmod-manufacturing'), count($assembly->open), count($positions))) . '</summary>'
                . '<div style="columns:2;margin:.5em 0">' . $boxes . '</div></details>';

        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="border-top:1px solid #dcdcde;padding:.75em 0">'
            . wp_nonce_field(self::NONCE, '_wpnonce', true, false) . $this->hidden(['action' => self::ACTION, 'do' => 'assembly', 'order' => (string) $order->id, 'assembly' => (string) $assembly->id, 'tab' => $this->tab()])
            . '<div style="display:flex;align-items:center;gap:1em"><strong style="flex:1">' . esc_html($revision) . '</strong>'
            . '<label>' . esc_html__('Status', 'taxmod-manufacturing') . ' <select name="step">' . $options . '</select></label>'
            . '<button type="submit" class="button">' . esc_html__('Speichern', 'taxmod-manufacturing') . '</button></div>'
            . ($this->service->structure()->hasField('assembly_note') ? '<label style="display:block;margin-top:.5em">' . esc_html__('Hinweis', 'taxmod-manufacturing') . ' <input type="text" class="regular-text" name="note" value="' . esc_attr($assembly->note) . '"></label>' : '')
            . $boxes . '</form>';
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
            . $this->hidden(['action' => self::ACTION, 'order' => (string) $order->id, 'tab' => $this->tab()])
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
            'assemblies_not_done'      => __('Erst wenn alle Platinen fertig sind, lässt sich der Auftrag abschließen.', 'taxmod-manufacturing'),
            'position_not_on_revision' => __('Diese Position steht nicht in der Stückliste der Revision.', 'taxmod-manufacturing'),
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

    private function assemblyStepLabel(string $step): string
    {
        return [
            'build_open'      => __('offen', 'taxmod-manufacturing'),
            'build_parts'     => __('Teile da', 'taxmod-manufacturing'),
            'build_assembled' => __('bestückt', 'taxmod-manufacturing'),
            'build_tested'    => __('geprüft', 'taxmod-manufacturing'),
            'build_done'      => __('fertig', 'taxmod-manufacturing'),
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
