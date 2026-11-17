<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress\Admin;

use Taxmod\WordPress\Admin\ListPage;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Demand;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\Staging;
use TaxmodManufacturing\WordPress\ManufacturingService;

/**
 * The acts of the orders page (admin-post): capability → nonce → validate → sanitize → act → back (CD-5, D-1254). Split
 * from the page at the architecture's word 16.11.: the page draws, this class acts.
 *
 * @see /mnt/project-files/taxmod-fertigung/spezifikation-fertigungsprozess.md
 */
final class OrderActions
{
    public function __construct(private readonly OrdersPage $page, private readonly ManufacturingService $service)
    {
    }

    /**
     * admin-post do=assign (D-1387): the material search's choice for one position becomes a reservation for it.
     *
     * @param array<string, string|int> $back
     */
    private function assign(int $order, string $today, array &$back): string
    {
        $chosen          = (array) ($_POST['assign'] ?? []);
        $thing           = absint($chosen['thing'] ?? 0);
        $place           = absint($chosen['place'] ?? 0);
        $qty             = (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) ($chosen['quantity'] ?? '0'))));
        $position        = absint($_POST['position'] ?? 0);
        $back['auftrag'] = $order;

        if ($thing === 0 || $place === 0 || $qty <= 0.0) {
            return 'nothing_picked';
        }

        $this->service->reserve($order, [['thing' => $thing, 'from' => $place, 'qty' => $qty] + ($position > 0 ? ['position' => $position] : [])], $today);

        return 'assigned';
    }

    /**
     * admin-post: capability → nonce → validate → sanitize → act → back to the page (CD-5). As an act (D-1254): an error the
     * page does not name itself becomes a message in the message field with the way back, never an empty page.
     */
    public function handle(string $action): void
    {
        $back = $this->page->url(['tab' => sanitize_key((string) ($_POST['tab'] ?? ''))]);

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
            $this->page->refuse(__('Keine Berechtigung.', 'taxmod-manufacturing'));
        }

        if (method_exists(ListPage::class, 'nonce')) {
            ListPage::nonce(OrdersPage::NONCE);
        } elseif (! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), OrdersPage::NONCE)) {
            $this->page->refuse(__('Das Formular ist abgelaufen. Bitte noch einmal.', 'taxmod-manufacturing'));
        }

        $today = current_time('Y-m-d');
        $tab   = sanitize_key((string) ($_POST['tab'] ?? ''));
        $back  = ['tab' => $tab];

        try {
            if ($action === OrdersPage::BULK_CANCEL || $action === OrdersPage::BULK_REOPEN) {
                foreach (array_filter(array_map('absint', (array) ($_POST['selected'] ?? []))) as $order) {
                    $this->service->setStep($order, $action === OrdersPage::BULK_CANCEL ? 'order_cancelled' : 'reopen', $today);
                }

                $back['msg'] = $action === OrdersPage::BULK_CANCEL ? 'cancelled' : 'reopened';
            } elseif ($action === OrdersPage::BULK_PICK || $action === OrdersPage::BULK_RESERVE) {
                $back['msg'] = $this->pick(absint($_POST['order'] ?? 0), $today, $back, $action === OrdersPage::BULK_RESERVE);
            } elseif (in_array($action, OrdersPage::FITTING, true)) {
                $back['msg'] = $this->fitBulk(absint($_POST['order'] ?? 0), $action, $today, $back);
            } elseif (in_array($action, TestSection::ACTIONS, true)) {
                $back['msg'] = $this->test(absint($_POST['order'] ?? 0), $action, $today, $back);
            } elseif ($action === StagingSection::CHANGE || $action === StagingSection::RELEASE) {
                $back['msg'] = $this->changeReservations(absint($_POST['order'] ?? 0), $today, $back, $action === StagingSection::RELEASE);
            } else {
                $do    = sanitize_key((string) ($_POST['do'] ?? (isset($_POST['readd']) ? 'readd' : '')));
                $order = absint($_POST['order'] ?? 0);

                $back['msg'] = match ($do) {
                    'create'   => $this->create($back),
                    'box'      => $this->box($order, $back),
                    'head'     => $this->head($order, $today, $back),
                    'options'  => $this->options($order, $today, $back),
                    'readd'    => $this->readd($order, $today, $back),
                    'back'     => $this->stepBack($order, $back),
                    'assign'   => $this->assign($order, $today, $back),
                    'reorder'  => $this->reorder($order, $back),
                    'staging'  => $this->step($order, 'order_staging', $today, $back),
                    'clear'    => $this->clear($order, $today, $back),
                    'building' => $this->toBuilding($order, $today, $back),
                    'finish'   => $this->finish($order, $today, $back),
                    'done'     => $this->step($order, 'order_done', $today, $back),
                    'cancel'   => $this->step($order, 'order_cancelled', $today, $back),
                    'reopen'   => $this->step($order, 'reopen', $today, $back),
                    default    => throw ManufacturingError::unknownStatus($do),
                };
            }
        } catch (ManufacturingError $error) {
            $back['err'] = rawurlencode($this->page->message($error));
            unset($back['msg']);
        }

        // An order's page marks the menu point of its step, not of the list it was opened from (his report 16.11.).
        if (isset($back['auftrag'])) {
            try {
                $step         = $this->service->order((int) $back['auftrag'])->step;
                $back['tab']  = $step === 'order_cancelled' ? 'cancelled' : $step;
            } catch (ManufacturingError) {
                // The order is gone; the list it came from stays.
            }
        }

        wp_safe_redirect($this->page->url($back));
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

    /**
     * The fitting of every board of the order, sent with the master data by its one 💾 (his report 17.11. «button chaos»: one
     * save). Fields are named per assembly: `fitted[assembly][position]`, `unused[assembly][]`, `scrap[assembly][position]`,
     * `note[assembly]`, `scrap_reason[assembly]`.
     */
    private function saveFittings(int $order, string $today): void
    {
        $decimal = static fn (mixed $v): float => max(0.0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) $v))));
        $sent    = (array) ($_POST['fitted'] ?? []);
        $notes   = (array) ($_POST['note'] ?? []);

        foreach (array_unique([...array_keys($sent), ...array_keys($notes)]) as $assembly) {
            $assembly = absint($assembly);
            $fitted   = [];
            $scrap    = [];

            foreach ((array) ($sent[$assembly] ?? []) as $position => $pieces) {
                $fitted[absint($position)] = $decimal($pieces);
            }

            foreach ((array) ($_POST['scrap'][$assembly] ?? []) as $position => $pieces) {
                $scrap[absint($position)] = $decimal($pieces);
            }

            $unused = array_values(array_filter(array_map('absint', (array) ($_POST['unused'][$assembly] ?? []))));
            $note   = isset($notes[$assembly]) ? sanitize_text_field(wp_unslash((string) $notes[$assembly])) : null;

            $this->service->updateAssembly($order, $assembly, $fitted, $unused, null, null, $note, $today);
            // After the counts, which check themselves before booking anything: scrap is its own booking (D-1302).
            $this->service->bookScrap($order, $assembly, $scrap, sanitize_text_field(wp_unslash((string) ($_POST['scrap_reason'][$assembly] ?? ''))), $today);
        }
    }

    /**
     * D-1419: the ticked boards of the test list — passed, failed with a note, or scrapped with a reason; each one act at the stock.
     *
     * @param array<string, string|int> $back
     */
    private function test(int $order, string $action, string $today, array &$back): string
    {
        $back['auftrag'] = $order;
        $lots            = array_values(array_filter(array_map('absint', (array) ($_POST['lots'] ?? []))));

        if ($action === TestSection::SCRAP) {
            $this->service->scrapBoards($order, $lots, sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? ''))), $today);

            return 'scrapped';
        }

        $passed      = $action === TestSection::PASSED;
        $back['act'] = $this->service->recordTest($order, $lots, $passed ? 'passed' : 'failed', $passed ? '' : sanitize_text_field(wp_unslash((string) ($_POST['note'] ?? ''))), $today);

        return $passed ? 'test_passed' : 'test_failed';
    }

    /**
     * D-1419 «Fertigung abschließen»: n boards completed in one act — consumption, the boards onto «Test», «x von N
     * abgeschlossen»; all N take the order to «Test».
     *
     * @param array<string, string|int> $back
     */
    private function finish(int $order, string $today, array &$back): string
    {
        $back['auftrag'] = $order;
        $categories      = [];

        foreach ((array) ($_POST['category'] ?? []) as $assembly => $category) {
            $categories[absint($assembly)] = absint($category);
        }

        $done = $this->service->finishBuild($order, absint($_POST['boards'] ?? 0), $today, $categories);

        if ($done['act'] !== null) {
            $back['act'] = $done['act'];
        }

        return $done['step'] === 'order_test' ? 'finished' : 'finished_part';
    }

    /**
     * D-1403, his word 17.11. «keine kästchen am anfang für bulk operation»: the fitting table's choices on the ticked positions.
     * «Voll bestückt» sets each to all pieces of the order, «Bestückt auf 0» to none; «Nicht verwenden» / «Wieder verwenden»
     * leave a position out of this build or take it back (D-1384).
     *
     * @param array<string, string|int> $back
     */
    private function fitBulk(int $order, string $action, string $today, array &$back): string
    {
        $assembly        = absint($_POST['assembly'] ?? 0);
        $selected        = array_values(array_filter(array_map('absint', (array) ($_POST['selected'] ?? []))));
        $back['auftrag'] = $order;
        $built           = $this->service->order($order);
        $part            = $built->assembly($assembly) ?? throw ManufacturingError::assemblyNotOfOrder($assembly, $order);

        if ($selected === []) {
            return 'nothing_picked';
        }

        if ($action === OrdersPage::BULK_UNUSE || $action === OrdersPage::BULK_USE) {
            $unused = $action === OrdersPage::BULK_UNUSE
                ? array_values(array_unique([...$part->unused, ...$selected]))
                : array_values(array_diff($part->unused, $selected));
            $this->service->updateAssembly($order, $assembly, [], $unused, null, null, null, $today);

            return 'saved';
        }

        $counts = array_column($this->service->partsLists([$part->revision])[$part->revision] ?? [], 'count', 'id');
        $pieces = [];

        foreach ($selected as $position) {
            if (isset($counts[$position]) && ! in_array($position, $part->unused, true)) {
                $pieces[$position] = $action === OrdersPage::BULK_FIT_FULL ? (float) $counts[$position] * $built->count : 0.0;
            }
        }

        $this->service->updateAssembly($order, $assembly, $pieces, $part->unused, null, null, null, $today);

        return 'saved';
    }

    /**
     * The order's shortfall as read now, not as the page showed it: what lies nowhere (D-1156).
     *
     * @param array<string, string|int> $back
     */
    private function reorder(int $order, array &$back): string
    {
        $built            = $this->service->order($order);
        [$lists] = $this->page->context([$built]);
        $lists            = $this->service->assignedLists($built, $lists);
        $share            = $this->page->boxShare($this->service->orders(), $built) ?? $this->service->presentIn($built->box);
        $held             = $this->service->reservationRows($order);
        $demand           = Demand::of($built->assemblies, $lists, $built->count, Staging::available($share, $held, $built->box));
        $things           = array_values(array_unique(array_merge(...array_map(static fn (array $rows): array => array_column($rows, 'thing'), array_values($demand)) ?: [[]])));
        $quantities       = [];
        $back['auftrag']  = $order;

        foreach (Staging::lines($demand, $share, $held, $this->service->placesOf($things, (int) $built->box), $built->box) as $line) {
            foreach ($line['sources'] as $source) {
                if ($source['kind'] === 'none') {
                    $quantities[$line['thing']] = $source['quantity'];
                }
            }
        }

        if ($quantities === []) {
            return 'nothing_missing';
        }

        $this->service->reorder($order, $quantities);

        return 'reordered';
    }

    /** @param array<string, string|int> $back */
    private function pick(int $order, string $today, array &$back, bool $reserve): string
    {
        $quantities = (array) ($_POST['qty'] ?? []);
        $selected   = array_map(static fn ($key): string => sanitize_text_field(wp_unslash((string) $key)), (array) ($_POST['selected'] ?? []));
        $lines      = [];

        foreach ($selected as $key) {
            // A reserved row («res:line:thing:place») is moved like a free one; reserving it again would hold it twice.
            if (preg_match('/^(?:res:\d+:)?(\d+):(\d+)$/', $key, $match) !== 1 || ($reserve && str_starts_with($key, 'res:'))) {
                continue;
            }

            $qty = (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) ($quantities[$key] ?? '0'))));

            $piece = absint(((array) ($_POST['piece'] ?? []))[$key] ?? 0);

            if ($qty > 0.0) {
                // D-1398: pieces of a strip counted in pins — the stock cuts them whole from its strips at `from`.
                $lines[] = ['thing' => (int) $match[1], 'from' => (int) $match[2], 'qty' => $qty]
                    + ($piece > 0 ? ['piece' => $piece, 'pieces' => (int) ceil($qty / $piece)] : []);
            }
        }

        $cutQty = (array) ($_POST['cutqty'] ?? []);
        $cuts   = [];

        foreach ($selected as $key) {
            $key = str_starts_with($key, 'cut:') ? substr($key, 4) : '';
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

        if ($lines !== [] && $reserve) {
            $this->service->reserve($order, $lines, $today);

            return 'reserved';
        }

        if ($lines !== []) {
            $this->service->bookPick($order, $lines, $today);
        }

        return 'picked';
    }

    /**
     * «Reservierung ändern» / «aufheben» (his words 16.11.): the ticked reserved rows are given free in one Storno; on a
     * change each is reserved anew with its new quantity at the same place, keeping its position (D-1387). 0 gives it free.
     *
     * @param array<string, string|int> $back
     */
    private function changeReservations(int $order, string $today, array &$back, bool $release): string
    {
        $quantities      = (array) ($_POST['qty'] ?? []);
        $positions       = (array) ($_POST['position'] ?? []);
        $back['auftrag'] = $order;
        $lines           = [];
        $again           = [];

        foreach ((array) ($_POST['selected'] ?? []) as $key) {
            $key = sanitize_text_field(wp_unslash((string) $key));

            if (preg_match('/^res:(\d+):(\d+):(\d+)$/', $key, $match) !== 1) {
                continue;
            }

            $line = (int) $match[1];
            $qty  = max(0.0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash((string) ($quantities[$key] ?? '0')))));
            $old  = $this->reservedQuantity($order, $line);

            if (! $release && abs($qty - $old) < 1e-9) {
                continue;
            }

            $lines[$line] = $line;

            if (! $release && $qty > 0.0 && (int) $match[3] > 0) {
                $again[] = ['thing' => (int) $match[2], 'from' => (int) $match[3], 'qty' => $qty, 'position' => absint($positions[$key] ?? 0)];
            }
        }

        if ($lines === []) {
            return 'nothing_picked';
        }

        $this->service->release($order, array_values($lines));

        if ($again !== []) {
            $this->service->reserve($order, $again, $today);
        }

        return $release ? 'released' : 'changed';
    }

    private function reservedQuantity(int $order, int $line): float
    {
        foreach ($this->service->reservationRows($order) as $row) {
            if ($row['line'] === $line) {
                return $row['quantity'];
            }
        }

        return 0.0;
    }

    /** @param array<string, string|int> $back */
    private function box(int $order, array &$back): string
    {
        $this->service->setBox($order, absint($_POST['box'] ?? 0));
        $back['auftrag'] = $order;

        return 'box';
    }

    /** @param array<string, string|int> $back */
    private function head(int $order, string $today, array &$back): string
    {
        $built           = $this->service->order($order);
        $back['auftrag'] = $order;

        if (! $built->isClosed() && isset($_POST['count'])) {
            $revision = absint($_POST['revision'] ?? 0);
            $due      = isset($_POST['due']) ? sanitize_text_field(wp_unslash((string) $_POST['due'])) : null;
            $this->service->setHead($order, sanitize_text_field(wp_unslash((string) $_POST['count'])), $revision > 0 ? $revision : null, $due);
            $after = $this->service->order($order);

            // D-1300: a smaller count gives what it no longer needs free; the box keeps what was moved into it.
            if ($after->count < $built->count && $this->service->canReserve()) {
                [$lists] = $this->page->context([$after]);
                $this->service->releaseSurplus($after, $this->service->assignedLists($after, $lists));
            }
        }

        if (isset($_POST['box']) && absint($_POST['box']) !== (int) $built->box) {
            $this->service->setBox($order, absint($_POST['box']));
        }

        $this->saveFittings($order, $today);

        return 'head';
    }

    /** @param array<string, string|int> $back */
    private function options(int $order, string $today, array &$back): string
    {
        $optional = array_map('absint', (array) ($_POST['optional'] ?? []));
        $all      = isset($_POST['all']) ? sanitize_key((string) $_POST['all']) : '';
        $built    = match ($all) {
            '1'     => $optional,
            '0'     => [],
            default => array_map('absint', (array) ($_POST['build'] ?? [])),
        };

        $this->service->updateAssembly($order, absint($_POST['assembly'] ?? 0), [], array_values(array_diff($optional, $built)), null, null, null, $today);
        $back['auftrag'] = $order;

        return 'options';
    }

    /** @param array<string, string|int> $back */
    private function readd(int $order, string $today, array &$back): string
    {
        [$assembly, $position] = array_map('absint', explode(':', (string) ($_POST['readd'] ?? '')) + [0, 0]);
        $part            = $this->service->order($order)->assembly($assembly) ?? throw ManufacturingError::assemblyNotOfOrder($assembly, $order);
        $back['auftrag'] = $order;

        if (array_filter(array_column($part->fitted, 'boards')) !== []) {
            throw ManufacturingError::readdAfterUse($assembly);
        }

        $this->service->updateAssembly($order, $assembly, [], array_values(array_diff($part->unused, [$position])), null, null, null, $today);

        return 'readded';
    }

    /** @param array<string, string|int> $back */
    private function stepBack(int $order, array &$back): string
    {
        $built = $this->service->order($order);
        $this->service->stepBack($order);

        // His report 16.11.: back at «Angelegt» nothing stays reserved for the order; the box keeps what was moved (D-1384).
        if ($built->step === 'order_staging' && $this->service->canRelease()) {
            $this->service->release($order, null);
        }
        $back['auftrag'] = $order;

        return 'stepped_back';
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
        [$lists] = $this->page->context([$built]);
        $lists           = $this->service->assignedLists($built, $lists);
        $share           = $this->page->boxShare($this->service->orders(), $built) ?? $this->service->presentIn($built->box);
        $missing         = Demand::missingRows(Demand::of($built->assemblies, $lists, $built->count, Staging::available($share, $this->service->reservationRows($order), $built->box)));
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
}
