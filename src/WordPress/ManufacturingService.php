<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress;

use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\BuildOrders;
use TaxmodManufacturing\Core\Demand;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\OrderChanges;
use TaxmodManufacturing\Core\Structure;
use TaxmodManufacturing\Core\Table;

/**
 * The boundary's side of the build orders: reads the structure and the model, hands the core's change lists to the model.
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/fertigung/konzept.md
 */
final class ManufacturingService
{
    /** Option: the structure ids (nodes, fields, values), settable over REST so the ids of a model change need no release. */
    public const STRUCTURE = 'taxmod_manufacturing_structure';

    private ?Structure $structure = null;

    public function __construct(private readonly ModelGateway $model = new ModelGateway())
    {
    }

    public function structure(): Structure
    {
        if ($this->structure !== null) {
            return $this->structure;
        }

        $stored  = get_option(self::STRUCTURE, []);
        $file    = dirname(__DIR__, 2) . '/structure-live.json';
        $bundled = is_readable($file) ? json_decode((string) file_get_contents($file), true) : [];

        return $this->structure = Structure::fromArray(Structure::completed(is_array($stored) ? $stored : [], is_array($bundled) ? $bundled : []));
    }

    public function table(string $node): Table
    {
        return $this->model->table($this->structure()->node($node));
    }

    /** @return list<BuildOrder> */
    public function orders(): array
    {
        return (new BuildOrders($this->structure()))->all($this->table('order'), $this->table('assembly'), $this->table('fitting'));
    }

    public function order(int $id): BuildOrder
    {
        return (new BuildOrders($this->structure()))->one($id, $this->table('order'), $this->table('assembly'), $this->table('fitting'));
    }

    /**
     * @param list<int>|null $chosen revisions to build; null builds every board of the project (D-1300)
     * @return int the new order's id
     */
    public function create(int $project, string $count, ?int $box, string $title, ?array $chosen = null): int
    {
        $changes   = new OrderChanges($this->structure());
        $revisions = $changes->revisionsOf($project, $this->table('project'), $this->table('board'));

        if ($chosen !== null) {
            $revisions = array_values(array_intersect($revisions, $chosen));

            if ($revisions === []) {
                throw ManufacturingError::noBoardChosen($project);
            }
        }
        $answer    = $this->model->write($changes->create($project, $revisions, $count, $box, $title));

        return (int) ($answer['refs']['auftrag'] ?? 0);
    }

    /** Filter of the stock module that books a build's consumption (D-1146); without it nothing is booked. */
    public const BOOK_FILTER = 'taxmod_stock_book_build';
    public const PLACES_FILTER = 'taxmod_stock_places_of';
    public const OUTPUT_FILTER = 'taxmod_stock_book_output';
    public const CREATE_THING_FILTER = 'taxmod_stock_create_thing';
    public const PICK_FILTER   = 'taxmod_stock_book_pick';
    /** D9 (D-1320): longer strips of the same kind a missing strip can be cut from, and the booking of the cut. */
    public const CUT_SOURCES_FILTER = 'taxmod_stock_cut_sources';
    public const CUT_FILTER         = 'taxmod_stock_book_cut';

    /** Whether the stock module's purchasing takes a demand from here (D-1156). */
    public function canReorder(): bool
    {
        return class_exists(\TaxmodStock\WordPress\StockService::class) && method_exists(\TaxmodStock\WordPress\StockService::class, 'reorder');
    }

    /**
     * What no place holds goes to purchasing as a new demand named after the order (D-1156); packs and suppliers are chosen there (D-1027).
     *
     * @param array<int, float> $quantities thing → pieces
     */
    public function reorder(int $order, array $quantities): void
    {
        $built = $this->order($order);

        if (! $this->canReorder()) {
            throw ManufacturingError::stockRefused(__('Der Einkauf im Lager nimmt noch keinen Bedarf an.', 'taxmod-manufacturing'));
        }

        $lines = [];

        foreach ($quantities as $thing => $pieces) {
            $lines[$thing] = rtrim(rtrim(number_format($pieces, 3, '.', ''), '0'), '.');
        }

        try {
            (new \TaxmodStock\WordPress\StockService())->reorder($lines, null, sprintf(__('Bedarf %s', 'taxmod-manufacturing'), $built->label));
        } catch (\RuntimeException $error) {
            throw ManufacturingError::stockRefused($error->getMessage());
        }
    }

    /** The stock page of a part, where its stock is shown; null while the stock module offers none. */
    public function stockUrl(int $thing): ?string
    {
        $page = \TaxmodStock\WordPress\Admin\StockPage::class;

        return class_exists($page) && method_exists($page, 'thingUrl') ? $page::thingUrl($thing, 'bestand') : null;
    }

    /** Whether the stock module offers the picking list's two interfaces (D-1155). */
    public function canPick(): bool
    {
        return has_filter(self::PLACES_FILTER) && has_filter(self::PICK_FILTER);
    }

    /**
     * Where these parts lie, outside the box: the stock module answers (D-1155).
     *
     * @param list<int> $things
     * @return array<int, array<int, float>> thing → place → pieces
     */
    public function placesOf(array $things, int $box): array
    {
        if ($things === [] || ! has_filter(self::PLACES_FILTER)) {
            return [];
        }

        $answer = apply_filters(self::PLACES_FILTER, null, ['things' => $things, 'except' => $box]);
        $places = [];

        foreach (is_array($answer) ? $answer : [] as $thing => $at) {
            foreach (is_array($at) ? $at : [] as $place => $pieces) {
                if (is_numeric($pieces) && (float) $pieces > 0.0) {
                    $places[(int) $thing][(int) $place] = (float) $pieces;
                }
            }
        }

        return $places;
    }

    /** D-1302: parts lost while building go out of the box as Verbrauch › Defekt/Ausschuss (D-1297); the stock module books it. */
    public const SCRAP_FILTER = 'taxmod_stock_book_scrap';

    public function canScrap(): bool
    {
        return has_filter(self::SCRAP_FILTER);
    }

    /**
     * Books scrapped parts of one assembly out of the order's box (D-1302). The reason is «Ausschuss Fertigung» plus the
     * optional extra reason.
     *
     * @param array<int, float> $pieces position → scrapped pieces; position 0 is the bare board
     */
    public function bookScrap(int $order, int $assembly, array $pieces, string $extra, string $today): bool
    {
        $pieces = array_filter($pieces, static fn (float $n): bool => $n > 0.0);

        if ($pieces === []) {
            return false;
        }

        $built = $this->order($order);
        $part  = $built->assembly($assembly) ?? throw ManufacturingError::assemblyNotOfOrder($assembly, $order);

        if ($built->box === null) {
            throw ManufacturingError::stockRefused(__('Dem Auftrag ist kein Gebinde zugeordnet.', 'taxmod-manufacturing'));
        }

        if (! $this->canScrap()) {
            throw ManufacturingError::stockRefused(__('Das Lager bietet das Buchen von Ausschuss noch nicht an.', 'taxmod-manufacturing'));
        }

        $things = array_column($this->partsLists([$part->revision])[$part->revision] ?? [], 'thing', 'id');
        $things[0] = $this->boardsOfRevisions([$part->revision])[$part->revision] ?? $part->revision;
        $lines  = [];

        foreach ($pieces as $position => $n) {
            if (isset($things[$position]) && $things[$position] > 0) {
                $lines[$things[$position]] = ($lines[$things[$position]] ?? 0.0) + $n;
            }
        }

        $reason = __('Ausschuss Fertigung', 'taxmod-manufacturing') . ($extra === '' ? '' : ': ' . $extra);
        $answer = apply_filters(self::SCRAP_FILTER, null, ['reference' => $order, 'place' => $built->box, 'lines' => $lines, 'reason' => $reason, 'title' => $built->label, 'date' => $today]);

        if (is_wp_error($answer)) {
            throw ManufacturingError::stockRefused($answer->get_error_message());
        }

        if ($answer === null) {
            throw ManufacturingError::stockRefused(__('Das Lager hat nicht geantwortet.', 'taxmod-manufacturing'));
        }

        return true;
    }

    public function canCut(): bool
    {
        return has_filter(self::CUT_SOURCES_FILTER) && has_filter(self::CUT_FILTER);
    }

    /**
     * Longer strips a missing strip can be cut from, asked once for all (CD-7); the stock module decides what is the same kind.
     *
     * @param array<int, float> $needs thing → pieces missing
     * @return array<int, list<array{thing: int, pins: int, lot: int, place: int, left: float}>> missing thing → sources
     */
    public function cutSources(array $needs, int $box): array
    {
        if ($needs === [] || ! $this->canCut()) {
            return [];
        }

        $answer = apply_filters(self::CUT_SOURCES_FILTER, null, ['things' => $needs, 'except' => $box]);
        $out    = [];

        foreach (is_array($answer) ? $answer : [] as $thing => $sources) {
            foreach (is_array($sources) ? $sources : [] as $source) {
                if (is_array($source) && isset($source['thing'], $source['lot'], $source['place'])) {
                    $out[(int) $thing][] = ['thing' => (int) $source['thing'], 'pins' => (int) ($source['pins'] ?? 0), 'lot' => (int) $source['lot'], 'place' => (int) $source['place'], 'left' => (float) ($source['left'] ?? 0)];
                }
            }
        }

        return $out;
    }

    /**
     * Books the ticked cuts as one transaction at the stock module: the long strip used, the pieces into the box, the rest
     * back at its place, the loss per cut (D-1178, D9).
     *
     * @param list<array{from: int, thing: int, pieces: float}> $cuts
     */
    public function bookCut(int $order, array $cuts, string $today): void
    {
        $built = $this->order($order);

        if ($built->box === null) {
            throw ManufacturingError::stockRefused(__('Dem Auftrag ist kein Gebinde zugeordnet.', 'taxmod-manufacturing'));
        }

        if (! has_filter(self::CUT_FILTER)) {
            throw ManufacturingError::stockRefused(__('Das Lager bietet das Ablängen noch nicht an.', 'taxmod-manufacturing'));
        }

        $answer = apply_filters(self::CUT_FILTER, null, ['reference' => $order, 'into' => $built->box, 'cuts' => $cuts, 'title' => $built->label, 'date' => $today]);

        if (is_wp_error($answer)) {
            throw ManufacturingError::stockRefused($answer->get_error_message());
        }

        if ($answer === null) {
            throw ManufacturingError::stockRefused(__('Das Lager hat nicht geantwortet.', 'taxmod-manufacturing'));
        }
    }

    /**
     * Books the ticked lines of the picking list as one transfer into the order's box (D-1155).
     *
     * @param list<array{thing: int, from: int, qty: float}> $lines
     */
    public function bookPick(int $order, array $lines, string $today): int
    {
        $built = $this->order($order);

        if ($built->box === null) {
            throw ManufacturingError::stockRefused(__('Dem Auftrag ist kein Gebinde zugeordnet.', 'taxmod-manufacturing'));
        }

        if (! has_filter(self::PICK_FILTER)) {
            throw ManufacturingError::stockRefused(__('Das Lager bietet das Umbuchen für die Pickliste noch nicht an.', 'taxmod-manufacturing'));
        }

        $answer = apply_filters(self::PICK_FILTER, null, ['reference' => $order, 'place' => $built->box, 'lines' => $lines, 'title' => $built->label, 'date' => $today]);

        if (is_wp_error($answer)) {
            throw ManufacturingError::stockRefused($answer->get_error_message());
        }

        if (! is_int($answer)) {
            throw ManufacturingError::stockRefused(__('Das Lager hat nicht geantwortet.', 'taxmod-manufacturing'));
        }

        return $answer;
    }

    /**
     * Saves an assembly's counts. The consumption they mean is booked first, at the stock module, so a box that does not
     * hold enough stops the save; then the counts are written.
     *
     * @param array<int, float> $pieces position → fitted parts, as entered
     * @param list<int>       $unused
     * @return 'booked'|'nothing'|'unbooked' unbooked: there was something to book, but the stock module offers no interface yet
     */
    public function updateAssembly(int $order, int $assembly, array $pieces, array $unused, ?int $tested, ?int $done, ?string $note, string $today, ?int $sparte = null): string
    {
        $built = $this->order($order);
        $part  = $built->assembly($assembly) ?? throw ManufacturingError::assemblyNotOfOrder($assembly, $order);
        $list  = $this->partsLists([$part->revision])[$part->revision] ?? [];
        $all   = array_column($list, 'id');
        $fitted = Demand::boardsFromPieces($pieces, $list);
        // Only an option may be left out (his word: «bei optionalen … was nicht verwendet wird»).
        $options = $this->structure()->hasValue('fitting_optional') ? array_column(array_filter($list, static fn (array $p): bool => $p['fitting'] === 'optional'), 'id') : $all;
        $changes = (new OrderChanges($this->structure()))->updateAssembly($built, $assembly, $fitted, $unused, $all, $options, $tested, $done, $note, $today);
        $made    = $done === null ? 0 : $done - $part->done;
        $checked = $tested ?? $part->tested;
        $boards  = $this->boardsAfter($part, $fitted, $unused, $list);

        // D-1177 «prüfung ist pflicht»: finished ≤ tested ≤ fully fitted — checked before anything is booked.
        if ($checked > $boards) {
            throw ManufacturingError::testedBeyondFitted($checked, $boards);
        }

        if (($done ?? $part->done) > $checked) {
            throw ManufacturingError::doneBeyondTested($done ?? $part->done, $checked);
        }

        $booked  = 'nothing';
        $use     = Demand::consumption($part, $fitted, $list, $this->boardsOfRevisions([$part->revision])[$part->revision] ?? null);

        if ($use !== [] && has_filter(self::BOOK_FILTER)) {
            if ($built->box === null) {
                throw ManufacturingError::stockRefused(__('Dem Auftrag ist kein Gebinde zugeordnet.', 'taxmod-manufacturing'));
            }

            $answer = apply_filters(self::BOOK_FILTER, null, ['reference' => $order, 'place' => $built->box, 'lines' => $use, 'title' => $built->label, 'date' => $today]);

            if (is_wp_error($answer)) {
                throw ManufacturingError::stockRefused($answer->get_error_message());
            }

            $booked = 'booked';
        } elseif ($use !== []) {
            $booked = 'unbooked';
        }

        if ($made !== 0 && $this->canReceive()) {
            try {
                $this->bookOutput($built, $part->revision, $made, $today, $sparte);
            } catch (ManufacturingError $error) {
                if ($booked === 'booked') {
                    // The consumption is already booked; give it back so the box shows what it did before.
                    apply_filters(self::BOOK_FILTER, null, ['reference' => $order, 'place' => $built->box, 'lines' => array_map(static fn (float $q): float => -$q, $use), 'title' => $built->label, 'date' => $today]);
                }

                throw $error;
            }

            $booked = $booked === 'unbooked' ? 'unbooked' : 'booked';
        }

        $this->model->write($changes);

        return $booked;
    }

    /** Whether finished boards go into stock (D-1143): the model knows the material node and the stock module books it. */
    public function canReceive(): bool
    {
        $s = $this->structure();

        return $s->hasNode('hardware') && $s->hasField('assembled_revision') && has_filter(self::OUTPUT_FILTER) && has_filter(self::CREATE_THING_FILTER);
    }

    /**
     * Boards with every used position fitted once these counts are saved (D-1146).
     *
     * @param array<int, int> $fitted
     * @param list<int> $unused
     * @param list<array{id: int, fitting: string}> $list
     */
    private function boardsAfter(Assembly $part, array $fitted, array $unused, array $list): int
    {
        $counts = [];

        foreach ($list as $position) {
            if ($position['fitting'] === 'no' || in_array($position['id'], $unused, true)) {
                continue;
            }

            $counts[] = $fitted[$position['id']] ?? $part->fittedOn($position['id']);
        }

        return $counts === [] ? 0 : min($counts);
    }

    /** Finished boards into the order's box as their own material (D-1143), tested (D-1177); fewer finished give them back. */
    private function bookOutput(BuildOrder $order, int $revision, int $boards, string $today, ?int $sparte): void
    {
        if ($order->box === null) {
            throw ManufacturingError::stockRefused(__('Dem Auftrag ist kein Gebinde zugeordnet.', 'taxmod-manufacturing'));
        }

        $answer = apply_filters(self::OUTPUT_FILTER, null, ['reference' => $order->id, 'place' => $order->box, 'lines' => [$this->materialFor($revision, $sparte) => (float) $boards], 'title' => $order->label, 'date' => $today, 'tested' => true]);

        if (is_wp_error($answer)) {
            throw ManufacturingError::stockRefused($answer->get_error_message());
        }
    }

    /**
     * The material «assembled board» of a revision (D-1261): the hardware record whose «Revision» names it. Made at the first
     * finished board in the Sparte chosen then — the Sparte is where the record lies, nothing else stores it.
     */
    public function materialFor(int $revision, ?int $sparte = null): int
    {
        $existing = $this->existingMaterial($revision);

        if ($existing !== null) {
            return $existing;
        }

        $s = $this->structure();

        if ($sparte === null || $sparte <= 0) {
            throw ManufacturingError::sparteNeeded($revision);
        }

        // D-1176: the revision's name with «bestückt»; every other field stays empty until reviewed (D-1250, D-1255).
        $name   = sprintf(__('%s, bestückt', 'taxmod-manufacturing'), $this->labelsOf([$revision])[$revision] ?? '#' . $revision);
        $answer = apply_filters(self::CREATE_THING_FILTER, null, ['name' => $name, 'group' => $sparte]);

        if (is_wp_error($answer) || ! is_int($answer)) {
            throw ManufacturingError::stockRefused(is_wp_error($answer) ? $answer->get_error_message() : __('Das Lager hat kein Material angelegt.', 'taxmod-manufacturing'));
        }

        $this->model->write([['op' => 'set_values', 'record' => (string) $answer, 'values' => [(string) $s->field('assembled_revision') => (string) $revision]]]);
        $this->model->markForReview([$answer]);

        return $answer;
    }

    public function existingMaterial(int $revision): ?int
    {
        $found = $this->model->referrers([$revision], $this->structure()->field('assembled_revision'));

        return $found === [] ? null : min($found);
    }

    /**
     * The Sparte to suggest for a revision without its material yet: where another revision of the same board lies (D-1261).
     */
    public function suggestedSparte(int $revision): ?int
    {
        $s      = $this->structure();
        $boards = $this->model->referrers([$revision], $s->field('board_revisions'));

        if ($boards === []) {
            return null;
        }

        $table     = $this->model->records($boards);
        $revisions = [];

        foreach ($boards as $board) {
            foreach ($table->all($board, $s->field('board_revisions')) as $other) {
                $revisions[] = (int) $other;
            }
        }

        $nodes = $this->model->nodesOf($this->model->referrers(array_values(array_diff($revisions, [$revision])), $s->field('assembled_revision')));

        return $nodes === [] ? null : (int) reset($nodes);
    }

    public function setBox(int $order, int $box): void
    {
        $this->model->write((new OrderChanges($this->structure()))->setBox($this->order($order), $box));
    }

    /**
     * What lies in a box and every place in it, as the stock module counts it (D-1139) — thing → pieces. Empty without
     * the stock module.
     *
     * @return array<int, float>
     */
    public function presentIn(?int $box): array
    {
        $stock = \TaxmodStock\WordPress\StockService::class;

        if ($box === null || ! class_exists($stock) || ! method_exists($stock, 'presentIn')) {
            return [];
        }

        return array_map('floatval', (new $stock())->presentIn($box));
    }

    /** D-1325: the project boxes with their project and contents, read by the stock module in one go (CD-7). */
    public const PROJECT_BOXES_FILTER = 'taxmod_stock_project_boxes';

    /**
     * Every project box (D-1030, D-1325): its project, its name and how many pieces lie in it. The stock module answers;
     * without it the boxes come from the places in the model, without contents.
     *
     * @return array<int, array{project: int, label: string, pieces: ?float}> place → box
     */
    public function projectBoxes(): array
    {
        if (has_filter(self::PROJECT_BOXES_FILTER)) {
            $answer = apply_filters(self::PROJECT_BOXES_FILTER, null, []);
            $out    = [];

            foreach (is_array($answer) ? $answer : [] as $place => $box) {
                if (is_array($box) && (int) ($box['project'] ?? 0) > 0) {
                    $out[(int) $place] = ['project' => (int) $box['project'], 'label' => (string) ($box['label'] ?? '#' . $place), 'pieces' => isset($box['pieces']) ? (float) $box['pieces'] : null];
                }
            }

            return $out;
        }

        $s = $this->structure();

        if (! $s->hasNode('location') || ! $s->hasField('location_project')) {
            return [];
        }

        $places = $this->table('location');
        $out    = [];

        foreach ($places->ids() as $id) {
            $project = (int) $places->first($id, $s->field('location_project'));

            if ($project > 0) {
                $out[$id] = ['project' => $project, 'label' => (string) ($places->first($id, $s->field('label')) ?? '#' . $id), 'pieces' => null];
            }
        }

        return $out;
    }

    /**
     * The places that belong to a project (D-1030 project boxes), for the box of an order.
     *
     * @return array<int, string> place id → label
     */
    public function boxesOf(int $project): array
    {
        $s = $this->structure();

        if (! $s->hasNode('location') || ! $s->hasField('location_project')) {
            return [];
        }

        $places = $this->table('location');
        $out    = [];

        foreach ($places->ids() as $id) {
            if ((int) $places->first($id, $s->field('location_project')) === $project) {
                $out[$id] = (string) ($places->first($id, $s->field('label')) ?? '#' . $id);
            }
        }

        return $out;
    }

    /** @param 'order_staging'|'order_building'|'order_done'|'order_cancelled'|'reopen' $to */
    public function setStep(int $order, string $to, string $today, int $missing = 0, string $reason = ''): void
    {
        $this->model->write((new OrderChanges($this->structure()))->setStep($this->order($order), $to, $today, $missing, $reason));
    }

    /** @return list<int> the positions of a revision's parts list, in their order */
    public function positionsOf(int $revision): array
    {
        return array_map('intval', $this->table('board_revision')->all($revision, $this->structure()->field('revision_positions')));
    }

    /**
     * The positions of these revisions: the part, how many, the reference designators and «Best.» (D-954) — one read of the
     * positions node.
     *
     * @param list<int> $revisions
     * @return array<int, list<array{id: int, thing: int, count: float, fitting: string, ref: string}>> revision → positions
     */
    public function partsLists(array $revisions): array
    {
        $s         = $this->structure();
        $positions = $this->table('bom_position');
        $fitting   = static fn (?string $value): string => match (true) {
            $value !== null && $s->hasValue('fitting_optional') && (string) $s->value('fitting_optional') === $value => 'optional',
            $value !== null && $s->hasValue('fitting_no') && (string) $s->value('fitting_no') === $value              => 'no',
            // «extern»: needed by whoever uses the board (the Arduino under a shield), but not part of the build (D-1323).
            $value !== null && $s->hasValue('fitting_external') && (string) $s->value('fitting_external') === $value  => 'no',
            default                                                                                                     => '',
        };
        $out = [];

        foreach ($revisions as $revision) {
            foreach ($this->positionsOf($revision) as $position) {
                $out[$revision][] = [
                    'id'      => $position,
                    'thing'   => (int) $positions->first($position, $s->field('position_part')),
                    'count'   => (float) ($positions->first($position, $s->field('position_count')) ?? 1),
                    'fitting' => $s->hasField('position_fitting') ? $fitting($positions->first($position, $s->field('position_fitting'))) : '',
                    'ref'     => $s->hasField('position_ref') ? implode(', ', $positions->all($position, $s->field('position_ref'))) : '',
                ];
            }
        }

        $loss = $this->cutLoss(array_values(array_unique(array_merge(...array_map(static fn (array $l): array => array_column($l, 'thing'), array_values($out)) ?: [[]]))));

        return $loss === [] ? $out : array_map(static fn (array $list): array => Demand::withCutLoss($list, $loss), $out);
    }

    /**
     * Pins lost per cut (D-1178), from the stock records of these materials — one read of that node.
     *
     * @param list<int> $things
     * @return array<int, int> thing → pins, only above nothing
     */
    private function cutLoss(array $things): array
    {
        $s = $this->structure();

        if ($things === [] || ! $s->hasNode('lager') || ! $s->hasField('lager_thing') || ! $s->hasField('lager_cut_loss')) {
            return [];
        }

        $wanted = array_flip($things);
        $table  = $this->table('lager');
        $out    = [];

        foreach ($table->ids() as $id) {
            $thing = (int) $table->first($id, $s->field('lager_thing'));
            $per   = (int) $table->first($id, $s->field('lager_cut_loss'));

            if ($per > 0 && isset($wanted[$thing])) {
                $out[$thing] = $per;
            }
        }

        return $out;
    }

    /**
     * @param list<int> $records
     * @return array<int, string> record id → its label
     */
    /**
     * The board each revision belongs to: the bare board is stocked as the board record, not as its revision (D-1322).
     *
     * @param list<int> $revisions
     * @return array<int, int> revision → board
     */
    public function boardsOfRevisions(array $revisions): array
    {
        $boards = $this->table('board');
        $field  = $this->structure()->field('board_revisions');
        $want   = array_flip($revisions);
        $out    = [];

        foreach ($boards->ids() as $board) {
            foreach ($boards->all($board, $field) as $revision) {
                if (isset($want[(int) $revision])) {
                    $out[(int) $revision] = $board;
                }
            }
        }

        return $out;
    }

    /** @return array<int, string> newest revision id => «board · revision», one per board of the project (D-1300) */
    public function boardsOf(int $project): array
    {
        return $this->boardsOfProjects([$project])[$project] ?? [];
    }

    /**
     * Boards of several projects with one read of their labels (CD-7).
     *
     * @param list<int> $projects
     * @return array<int, array<int, string>> project id => (newest revision id => «board · revision»)
     */
    public function boardsOfProjects(array $projects): array
    {
        $changes  = new OrderChanges($this->structure());
        $projectT = $this->table('project');
        $boardT   = $this->table('board');
        $newest   = [];
        $ids      = [];

        foreach ($projects as $project) {
            $newest[$project] = $changes->newestRevisions($project, $projectT, $boardT);
            array_push($ids, ...array_keys($newest[$project]), ...array_values($newest[$project]));
        }

        $labels = $ids === [] ? [] : $this->labelsOf($ids);
        $out    = [];

        foreach ($newest as $project => $pairs) {
            foreach ($pairs as $board => $revision) {
                $out[$project][$revision] = $labels[$board] . ' · ' . $labels[$revision];
            }
        }

        return $out;
    }

    public function labelsOf(array $records): array
    {
        $table = $this->model->records(array_values(array_unique($records)));
        $field = $this->structure()->field('label');
        $out   = [];

        foreach ($records as $id) {
            $out[$id] = (string) ($table->first($id, $field) ?? '#' . $id);
        }

        return $out;
    }

    /** @return array<int, string> project id → label, sorted */
    public function projects(): array
    {
        $table = $this->table('project');
        $field = $this->structure()->field('label');
        $out   = [];

        foreach ($table->ids() as $id) {
            $out[$id] = (string) ($table->first($id, $field) ?? '#' . $id);
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }
}
