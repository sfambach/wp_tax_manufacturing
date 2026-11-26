<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress;

use Taxmod\WordPress\Admin\ListPage;
use Taxmod\Core\Renderer\DecimalText;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\BuildOrders;
use TaxmodManufacturing\Core\Costing;
use TaxmodManufacturing\Core\Demand;
use TaxmodManufacturing\Core\Findings;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\OrderChanges;
use TaxmodManufacturing\Core\Positions;
use TaxmodManufacturing\Core\RevisionStatus;
use TaxmodManufacturing\Core\Staging;
use TaxmodManufacturing\Core\Stopwatch;
use TaxmodManufacturing\Core\Structure;
use TaxmodManufacturing\Core\Systems;
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
    private ?bool $workedIsDuration = null;
    /** @var array<int, list<int>> D-1460: material made in this request → the systems taken over from its plan */
    private array $made = [];
    /** Review F1: the one stock service of this request, and the stock's write count it was made at. */
    private static ?\TaxmodStock\WordPress\StockService $stock = null;
    private static int $stockReadAt = -1;

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
        return (new BuildOrders($this->structure()))->all($this->table('order'), $this->table('assembly'), $this->table('fitting'), $this->bareBoards(), $this->workedSeconds());
    }

    public function order(int $id): BuildOrder
    {
        return (new BuildOrders($this->structure()))->one($id, $this->table('order'), $this->table('assembly'), $this->table('fitting'), $this->bareBoards(), $this->workedSeconds([$id]));
    }

    /**
     * Feldkatalog 21.11.: whether «Erfasste Zeit» is a duration (a unit value) rather than a plain number — the modeller
     * says so, so the same code serves before and after the data job.
     */
    public function workedIsDuration(): bool
    {
        $s = $this->structure();

        return $this->workedIsDuration ??= $s->hasField('order_worked') && $this->model->amountFields([$s->field('order_worked')]) !== []
            && method_exists(\Taxmod\WordPress\ModelDoor::class, 'inAct');
    }

    /**
     * Feldkatalog 21.11.: «Erfasste Zeit» in seconds where it is a duration, read in one go (CD-7); the modeller converts.
     *
     * @param list<int>|null $orders null: every order
     * @return array<int, float> order → seconds
     */
    private function workedSeconds(?array $orders = null): array
    {
        if (! $this->workedIsDuration()) {
            return [];
        }

        $field = $this->structure()->field('order_worked');

        $out = [];

        foreach ($this->model->amountsOf($orders ?? $this->table('order')->ids(), [$field]) as $order => $fields) {
            isset($fields[$field]) && $out[(int) $order] = (float) $fields[$field];
        }

        return $out;
    }

    /**
     * D-1572: every bare board material with the revision it is made for, read once (CD-7); empty until the model has
     * «Platinen» and «für Revision» — then no assembly has a bare board (step 3: the revision itself is no material). A second board for the same
     * revision is not taken: the relation is 1:1, the first one counts.
     *
     * @return array<int, int> revision → bare board
     */
    public function bareBoards(): array
    {
        $s = $this->structure();

        if (! $s->hasNode('bare_board') || ! $s->hasField('bare_board_revision')) {
            return [];
        }

        $boards = $this->table('bare_board');
        $out    = [];

        foreach ($boards->ids() as $board) {
            $revision = (int) ($boards->first($board, $s->field('bare_board_revision')) ?? 0);

            if ($revision > 0 && ! isset($out[$revision])) {
                $out[$revision] = $board;
            }
        }

        return $out;
    }

    /** D-1572, his word 21.11. 19:25: «aus einer Revision dann ein Platinenmaterial generieren» — possible once the model has both. */
    public function canMakeBareBoard(): bool
    {
        $s = $this->structure();

        return $s->hasNode('bare_board') && $s->hasField('bare_board_revision') && $s->hasNode('board_revision') && has_filter(self::CREATE_THING_FILTER);
    }

    /**
     * D-1572: the bare board material of a revision — the one there is, else a new one in «Platinen» named like the revision,
     * pointing at it, with its pictures. One act (D-1407), so it is undone in one step.
     *
     * @return int the bare board's record id
     */
    public function makeBareBoard(int $revision): int
    {
        $s = $this->structure();

        if (! $this->canMakeBareBoard()) {
            throw ManufacturingError::notConfigured('bare_board_revision');
        }

        if (! $this->table('board_revision')->has($revision)) {
            throw ManufacturingError::notARevision($revision);
        }

        $have = $this->bareBoards()[$revision] ?? 0;

        if ($have > 0) {
            return $have;
        }

        $make = function () use ($s, $revision): int {
            $name   = $this->labelsOf([$revision])[$revision] ?? '#' . $revision;
            $answer = apply_filters(self::CREATE_THING_FILTER, null, ['name' => $name, 'group' => $s->node('bare_board')]);

            if (is_wp_error($answer) || ! is_int($answer)) {
                throw ManufacturingError::stockRefused(is_wp_error($answer) ? $answer->get_error_message() : __('Das Lager hat kein Material angelegt.', 'taxmod-manufacturing'));
            }

            $values = [(string) $s->field('bare_board_revision') => (string) $revision];

            // His word 21.11. 19:31: pictures at both — taken over once; afterwards each keeps its own.
            if ($s->hasField('images')) {
                $images = array_values(array_map('strval', $this->table('board_revision')->all($revision, $s->field('images'))));

                if ($images !== []) {
                    $values[(string) $s->field('images')] = ['values' => $images];
                }
            }

            $this->model->write([['op' => 'set_values', 'record' => $answer, 'values' => $values]]);

            return $answer;
        };

        if (! method_exists(\Taxmod\WordPress\ModelDoor::class, 'inAct')) {
            return $make();
        }

        return (int) (\Taxmod\WordPress\ModelDoor::inAct($make)['result'] ?? 0);
    }

    /**
     * @param list<int>|null $chosen revisions to build; null builds every board of the project (D-1300)
     * @return int the new order's id
     */
    public function create(int $project, string $count, ?int $box, string $title, ?array $chosen = null, bool $kit = false): int
    {
        $changes   = new OrderChanges($this->structure());
        $revisions = $changes->revisionsOf($project, $this->table('project'), $this->table('board'));

        if ($chosen !== null) {
            $revisions = array_values(array_intersect($revisions, $chosen));

            if ($revisions === []) {
                throw ManufacturingError::noBoardChosen($project);
            }
        }
        $answer    = $this->model->write($changes->create($project, $revisions, $count, $box, $title, $kit));

        return (int) ($answer['refs']['auftrag'] ?? 0);
    }

    /** Filter of the stock module that books a build's consumption (D-1146); without it nothing is booked. */
    public const BOOK_FILTER = 'taxmod_stock_book_build';
    public const PLACES_FILTER = 'taxmod_stock_places_of';
    public const OUTPUT_FILTER = 'taxmod_stock_book_output';
    public const TEST_PLACE_FILTER = 'taxmod_stock_test_place';
    public const CREATE_THING_FILTER = 'taxmod_stock_create_thing';
    public const PICK_FILTER   = 'taxmod_stock_book_pick';
    /** D9 (D-1320): longer strips of the same kind a missing strip can be cut from, and the booking of the cut. */
    public const CUT_SOURCES_FILTER = 'taxmod_stock_cut_sources';
    public const CUT_FILTER         = 'taxmod_stock_book_cut';
    /** D-1535 (P1): the stock's one cut plan of all pieces of a strip counted in pins, per place. */
    public const PIN_CUT_PLANS_FILTER = 'taxmod_stock_pin_cut_plans';
    /** D-1535 (P4): plain strip → its round-contact counterpart, from the stock. */
    public const ROUND_FILTER = 'taxmod_stock_round_counterparts';

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
            $lines[$thing] = DecimalText::stored($pieces, 3);
        }

        try {
            $this->stock()->reorder($lines, null, sprintf(__('Bedarf %s', 'taxmod-manufacturing'), $built->label));
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
     * D-1539: stand-ins in the order's box for the missing materials — one read of the box, one of the catalog nodes (CD-7).
     *
     * @param array<int, float> $missing thing → pieces still missing
     * @param list<int>         $needed  the order's own materials
     * @return array<int, array<int, float>> missing thing → stand-in → pieces in the box
     */
    public function substitutesInBox(array $missing, array $needed, ?int $box): array
    {
        $missing = array_filter($missing, static fn (float $q): bool => $q > 1e-9);
        $content = $missing === [] ? [] : $this->presentIn($box);

        if ($content === []) {
            return [];
        }

        $nodes = array_map('intval', $this->model->nodesOf(array_values(array_unique([...array_keys($missing), ...array_keys($content)]))));
        $alike = $this->model->sameKeyValuesOf(Staging::candidates($missing, $nodes, $content, $needed));

        return Staging::substitutes($missing, $nodes, $content, $needed, $alike);
    }

    public const THINGS_IN_STOCK_FILTER = 'taxmod_stock_things_in_stock';

    public const UNITS_FILTER = 'taxmod_stock_units_of';

    /**
     * Stefan 21.11. 15:08: the unit word of each material, from the stock in one call; none where the stock does not say.
     *
     * @param list<int> $things
     * @return array<int, string>
     */
    public function unitsOf(array $things): array
    {
        if ($things === [] || ! has_filter(self::UNITS_FILTER)) {
            return [];
        }

        $answer = apply_filters(self::UNITS_FILTER, null, ['things' => $things]);

        return is_array($answer) ? array_filter(array_map('strval', $answer), static fn (string $u): bool => $u !== '') : [];
    }

    /**
     * D-1539: stand-ins in the rest of the stock, with the places they lie at — one nodesOf, one stock read, one key-value comparison (D-1540)
     * of the missing materials, one placesOf for all stand-ins.
     *
     * @param array<int, float> $missing thing → pieces still missing
     * @param list<int>         $needed  the order's own materials
     * @return array<int, array<int, array<int, float>>> missing thing → stand-in → place → free pieces
     */
    public function substitutesInStock(array $missing, array $needed, ?int $box): array
    {
        $missing = array_filter($missing, static fn (float $q): bool => $q > 1e-9);

        if ($missing === [] || ! has_filter(self::THINGS_IN_STOCK_FILTER) || ! $this->model->comparesKeyValues()) {
            return [];
        }

        $nodes  = array_map('intval', $this->model->nodesOf(array_keys($missing)));
        $wanted = array_values(array_unique(array_filter(array_values($nodes))));
        $free   = [];

        // CD-7, Prüftor 20.11.: one read for all nodes from the stock 0.64.180 on; an older stock is asked once per node.
        if (defined('TAXMOD_STOCK_VERSION') && version_compare((string) TAXMOD_STOCK_VERSION, '0.64.180', '>=')) {
            $answer = apply_filters(self::THINGS_IN_STOCK_FILTER, null, ['nodes' => $wanted, 'except' => (int) $box]);

            foreach ($wanted as $node) {
                $free[$node] = is_array($answer) && is_array($answer[$node] ?? null) ? array_map('floatval', array_filter($answer[$node], 'is_numeric')) : [];
            }
        } else {
            foreach ($wanted as $node) {
                $answer      = apply_filters(self::THINGS_IN_STOCK_FILTER, null, ['node' => $node, 'except' => (int) $box]);
                $free[$node] = is_array($answer) ? array_map('floatval', array_filter($answer, 'is_numeric')) : [];
            }
        }

        $pool  = [];
        $owner = $nodes;

        foreach ($free as $node => $things) {
            foreach ($things as $other => $pieces) {
                $pool[$other]  = $pieces;
                $owner[$other] = $node;
            }
        }

        $alike    = $this->model->sameKeyValuesOf(Staging::candidates($missing, $owner, $pool, $needed));
        $standIns = Staging::standIns($missing, $nodes, $free, $needed, $alike);
        $places   = $standIns === [] ? [] : $this->placesOf(array_values(array_unique(array_merge(...array_values($standIns)))), (int) $box);
        $out      = [];

        foreach ($standIns as $thing => $others) {
            foreach ($others as $other) {
                if (($places[$other] ?? []) !== []) {
                    $out[$thing][$other] = $places[$other];
                }
            }
        }

        return $out;
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

    /** D-1301: reservations with the order as subject (D-1083), and «Box leer räumen». */
    public const RESERVE_FILTER      = 'taxmod_stock_reserve';
    public const RESERVATIONS_FILTER = 'taxmod_stock_reservations_of';
    public const RETURN_FILTER       = 'taxmod_stock_book_return';

    public function canReserve(): bool
    {
        return has_filter(self::RESERVE_FILTER) && has_filter(self::RESERVATIONS_FILTER);
    }

    /** @var array<string, mixed> the stock's answer per set of orders, for this request (his report 16.11.: slow lists) */
    private array $reservationLines = [];

    /**
     * The stock's open reservation lines of these orders, asked once per request: the list and the order page read them
     * for what is reserved and for what is assigned, and each ask builds the stock's ledger anew.
     *
     * @param list<int> $orders
     */
    private function reservationLines(array $orders): mixed // mixed: whatever the stock's filter answers, checked by the callers
    {
        $key = implode(',', $orders);

        return $this->reservationLines[$key] ??= apply_filters(self::RESERVATIONS_FILTER, null, ['subjects' => array_values($orders)]);
    }

    /**
     * The open reservations of these orders, per part, asked once for all (CD-7).
     *
     * A reservation whose lot already lies in the order's box is left out: the box counts it (his report 16.11.).
     *
     * @param list<int> $orders
     * @param array<int, int|null> $boxes order → its box
     * @return array<int, array<int, float>> order → thing → pieces reserved outside the box
     */
    public function reservedFor(array $orders, array $boxes = []): array
    {
        if ($orders === [] || ! has_filter(self::RESERVATIONS_FILTER)) {
            return [];
        }

        $answer = $this->reservationLines($orders);
        $out    = [];

        foreach (is_array($answer) ? $answer : [] as $order => $lines) {
            foreach (is_array($lines) ? $lines : [] as $line) {
                if (is_array($line) && (int) ($line['thing'] ?? 0) > 0) {
                    $box    = $boxes[(int) $order] ?? null;
                    $inBox  = $box !== null && is_array($line['places'] ?? null) ? (float) ($line['places'][$box] ?? 0.0) : 0.0;
                    $pieces = max(0.0, (float) ($line['quantity'] ?? 0) - $inBox);
                    $out[(int) $order][(int) $line['thing']] = ($out[(int) $order][(int) $line['thing']] ?? 0.0) + $pieces;
                }
            }
        }

        return $out;
    }

    /**
     * D-1582: what each order's reservation names inside its own box — given to it there, not shared by age.
     *
     * @param list<int> $orders
     * @param array<int, int|null> $boxes order → box
     * @return array<int, array<int, float>> order → thing → pieces
     */
    public function givenInBox(array $orders, array $boxes): array
    {
        if ($orders === [] || ! has_filter(self::RESERVATIONS_FILTER)) {
            return [];
        }

        $answer = $this->reservationLines($orders);
        $out    = [];

        foreach (is_array($answer) ? $answer : [] as $order => $lines) {
            $box = $boxes[(int) $order] ?? null;

            foreach ($box === null || ! is_array($lines) ? [] : $lines as $line) {
                $pieces = is_array($line) && is_array($line['places'] ?? null) ? min((float) ($line['quantity'] ?? 0), (float) ($line['places'][$box] ?? 0.0)) : 0.0;

                if ($pieces > 0.0 && (int) ($line['thing'] ?? 0) > 0) {
                    $out[(int) $order][(int) $line['thing']] = ($out[(int) $order][(int) $line['thing']] ?? 0.0) + $pieces;
                }
            }
        }

        return $out;
    }

    /** D-1582: the project a box belongs to, 0 for none. */
    public function projectOfBox(?int $box): int
    {
        return $box === null ? 0 : (int) ($this->projectBoxes()[$box]['project'] ?? 0);
    }

    /**
     * D-1387: what is assigned to each position of these orders — the reservations that name a position, one read for all
     * orders (CD-7).
     *
     * @param list<int> $orders
     * @return array<int, array<int, list<array{thing: int, quantity: float, places: array<int, float>}>>> order → position → reservations
     */
    public function assignments(array $orders): array
    {
        if ($orders === [] || ! has_filter(self::RESERVATIONS_FILTER)) {
            return [];
        }

        $answer = $this->reservationLines($orders);
        $out    = [];

        foreach (is_array($answer) ? $answer : [] as $order => $lines) {
            foreach (is_array($lines) ? $lines : [] as $line) {
                $position = is_array($line) ? (int) ($line['position'] ?? 0) : 0;

                if ($position > 0 && (int) ($line['thing'] ?? 0) > 0) {
                    $out[(int) $order][$position][] = [
                        'thing'    => (int) $line['thing'],
                        'quantity' => (float) ($line['quantity'] ?? 0),
                        'places'   => array_map('floatval', is_array($line['places'] ?? null) ? $line['places'] : []),
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * The reservations of an order that name no position — those ticked in the pick list and reserved where they lie (D-1301).
     * They count as there, so the pick list shows them as reserved rows (his report 16.11.: after «reservieren» the list was gone).
     *
     * @return array<int, list<array{thing: int, quantity: float, places: array<int, float>}>> thing → reservations
     */
    public function looseReservations(int $order): array
    {
        if (! has_filter(self::RESERVATIONS_FILTER)) {
            return [];
        }

        $answer = $this->reservationLines([$order]);
        $out    = [];

        foreach (is_array($answer[$order] ?? null) ? $answer[$order] : [] as $line) {
            if (is_array($line) && (int) ($line['position'] ?? 0) === 0 && (int) ($line['thing'] ?? 0) > 0) {
                $out[(int) $line['thing']][] = [
                    'thing'    => (int) $line['thing'],
                    'quantity' => (float) ($line['quantity'] ?? 0),
                    'places'   => array_map('floatval', is_array($line['places'] ?? null) ? $line['places'] : []),
                ];
            }
        }

        return $out;
    }

    /**
     * Every open reservation line of an order with its stock line id, for the staging table (spec. §3) and for changing or
     * releasing a reservation (his word 16.11. «reservierung ändern sollte auch möglich sein»).
     *
     * @return list<array{line: int, thing: int, quantity: float, places: array<int, float>, position: int}>
     */
    public function reservationRows(int $order): array
    {
        if (! has_filter(self::RESERVATIONS_FILTER)) {
            return [];
        }

        $answer = $this->reservationLines([$order]);
        $out    = [];

        foreach (is_array($answer[$order] ?? null) ? $answer[$order] : [] as $line) {
            if (is_array($line) && (int) ($line['line'] ?? 0) > 0 && (int) ($line['thing'] ?? 0) > 0 && (float) ($line['quantity'] ?? 0) > 0.0) {
                $out[] = [
                    'line'     => (int) $line['line'],
                    'thing'    => (int) $line['thing'],
                    'quantity' => (float) $line['quantity'],
                    'places'   => array_map('floatval', is_array($line['places'] ?? null) ? $line['places'] : []),
                    'position' => (int) ($line['position'] ?? 0),
                ];
            }
        }

        return $out;
    }

    /** Whether reservations can be given free (D-1301). */
    public function canRelease(): bool
    {
        return $this->canReserve() && has_filter(self::RELEASE_FILTER);
    }

    /**
     * Gives reservation lines of an order free in one Storno at the stock module; null gives all of them.
     *
     * @param list<int>|null $lines
     */
    public function release(int $order, ?array $lines): void
    {
        if (! $this->canRelease()) {
            throw ManufacturingError::stockRefused(__('Das Lager bietet das Freigeben von Reservierungen noch nicht an.', 'taxmod-manufacturing'));
        }

        if ($lines === [] || ($lines === null && $this->reservationRows($order) === [])) {
            return;
        }

        $done = apply_filters(self::RELEASE_FILTER, null, ['subject' => $order, 'lines' => $lines]);
        $this->reservationLines = [];

        if (is_wp_error($done)) {
            throw ManufacturingError::stockRefused($done->get_error_message());
        }
    }

    /**
     * The parts lists of an order with what is assigned to its positions (D-1387).
     *
     * @param array<int, list<array<string, mixed>>> $lists revision → positions
     * @param array<int, list<array{thing: int}>>|null $assigned position → reservations, from {@see assignments()}; null reads them
     * @return array<int, list<array<string, mixed>>>
     */
    public function assignedLists(BuildOrder $order, array $lists, ?array $assigned = null): array
    {
        $assigned ??= $this->assignments([$order->id])[$order->id] ?? [];

        return Demand::withAssigned($lists, array_map(static fn (array $rows): int => (int) ($rows[0]['thing'] ?? 0), $assigned));
    }

    /**
     * What counts as there for an order: its box and what is reserved for it (D-1301 «Gebinde und Reservierung»).
     *
     * @param array<int, float>|null $reserved thing → pieces, from {@see reservedFor()}; null asks for this order alone
     * @param array<int, float>|null $present thing → pieces in its box, from {@see presentInEach()}; null reads the box
     * @return array<int, float> thing → pieces
     */
    public function available(BuildOrder $order, ?array $reserved = null, ?array $present = null): array
    {
        $there = $present ?? $this->presentIn($order->box);

        foreach ($reserved ?? ($this->reservedFor([$order->id], [$order->id => $order->box])[$order->id] ?? []) as $thing => $pieces) {
            $there[$thing] = ($there[$thing] ?? 0.0) + $pieces;
        }

        return $there;
    }

    /**
     * Reserves parts for the order where they lie (D-1083): one act at the stock module, which picks the lots at each place.
     *
     * @param list<array{thing: int, from: int, qty: float}> $lines from the picking list
     */
    public function reserve(int $order, array $lines, string $today): void
    {
        $built = $this->order($order);
        $rows  = [];

        foreach ($lines as $line) {
            if ($line['qty'] > 0.0) {
                // D-1387: a line chosen for a position keeps it, so the position counts with that material.
                $rows[] = ['thing' => $line['thing'], 'place' => $line['from'], 'quantity' => (string) $line['qty']] + (($line['position'] ?? 0) > 0 ? ['position' => $line['position']] : []);
            }
        }

        if ($rows === []) {
            return;
        }

        if (! has_filter(self::RESERVE_FILTER)) {
            throw ManufacturingError::stockRefused(__('Das Lager bietet das Reservieren noch nicht an.', 'taxmod-manufacturing'));
        }

        // D-1582: the order's project, so the stock gives it what lies in that project's box.
        $answer = apply_filters(self::RESERVE_FILTER, null, ['subject' => $order, 'rows' => $rows, 'deadline' => '', 'date' => $today, 'title' => $built->label, 'project' => $this->projectOfBox($built->box)]);
        $this->reservationLines = [];

        if (is_wp_error($answer)) {
            throw ManufacturingError::stockRefused($answer->get_error_message());
        }
    }

    /** «Box leer räumen» (his word 11.11.): everything in the box goes back to where it came from, one transfer. */
    public function clearBox(int $order, string $today): void
    {
        $built = $this->order($order);

        if ($built->box === null) {
            throw ManufacturingError::stockRefused(__('Dem Auftrag ist kein Gebinde zugeordnet.', 'taxmod-manufacturing'));
        }

        if (! has_filter(self::RETURN_FILTER)) {
            throw ManufacturingError::stockRefused(__('Das Lager bietet das Zurückbuchen noch nicht an.', 'taxmod-manufacturing'));
        }

        $answer = apply_filters(self::RETURN_FILTER, null, ['box' => $built->box, 'reference' => $order, 'date' => $today, 'title' => $built->label]);

        if (is_wp_error($answer)) {
            throw ManufacturingError::stockRefused($answer->get_error_message());
        }
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

        $things = array_column($this->assignedLists($built, $this->partsLists([$part->revision]))[$part->revision] ?? [], 'thing', 'id');
        $things[0] = $part->boardThing();
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
     * @param list<array{from: int, thing: int, pieces: int}> $cuts
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
     * D-1535 (P4): plain strip → its round-contact counterpart, as the stock finds it (same kind, «Kontaktart»
     * Rundkontakt). Empty without the stock's filter.
     *
     * @param list<int> $things
     * @return array<int, int>
     */
    public function roundCounterparts(array $things): array
    {
        if ($things === [] || ! has_filter(self::ROUND_FILTER)) {
            return [];
        }

        $answer = apply_filters(self::ROUND_FILTER, null, ['things' => $things]);
        $out    = [];

        foreach (is_array($answer) ? $answer : [] as $thing => $round) {
            if ((int) $thing > 0 && is_numeric($round) && (int) $round > 0) {
                $out[(int) $thing] = (int) $round;
            }
        }

        return $out;
    }

    /**
     * D-1535 (P4) «Rundkontakt statt eckig»: each given position's missing pins are reserved as its plain strip's round
     * counterpart, from the place that holds most of it (D-1387: a substitute reserved for a position; the parts list
     * stays). A position whose strip has no counterpart in stock is left as it is.
     *
     * @param array<int, array{thing: int, quantity: float}> $positions position → plain strip and missing pins
     * @return int positions reserved
     */
    public function reserveRound(int $order, array $positions, string $today): int
    {
        $built  = $this->order($order);
        $round  = $this->roundCounterparts(array_values(array_unique(array_column($positions, 'thing'))));
        $places = $round === [] ? [] : $this->placesOf(array_values(array_unique($round)), (int) $built->box);
        $lines  = [];
        $left   = $places;

        foreach ($positions as $position => $want) {
            $other = $round[$want['thing']] ?? 0;
            $at    = $left[$other] ?? [];
            arsort($at);
            $place = (int) array_key_first($at);

            if ($other === 0 || $place === 0 || $at[$place] + 0.000001 < $want['quantity']) {
                continue;
            }

            $lines[]               = ['thing' => $other, 'from' => $place, 'qty' => $want['quantity'], 'position' => $position];
            $left[$other][$place] -= $want['quantity'];
        }

        $this->reserve($order, $lines, $today);

        return count($lines);
    }

    /**
     * D-1535 (P1): how the stock would cut these pieces at each place — read only; the plan is the stock's
     * (D-1398), this module only shows it. Empty without the stock's filter.
     *
     * @param list<int>       $places
     * @param array<int, int> $cuts pins per piece → pieces
     * @return array<int, array{opened: int, strips: list<array{pins: int, whole: bool, cuts: array<int, int>, rest: int, scrap: int}>}>
     */
    public function pinCutPlans(int $thing, array $places, array $cuts): array
    {
        if ($places === [] || $cuts === [] || ! has_filter(self::PIN_CUT_PLANS_FILTER)) {
            return [];
        }

        $answer = apply_filters(self::PIN_CUT_PLANS_FILTER, null, ['thing' => $thing, 'places' => $places, 'cuts' => $cuts]);
        $out    = [];

        foreach (is_array($answer) ? $answer : [] as $place => $plan) {
            if (! is_array($plan) || ! is_array($plan['strips'] ?? null)) {
                continue;
            }

            $strips = [];

            foreach ($plan['strips'] as $strip) {
                if (is_array($strip)) {
                    $strips[] = ['pins' => (int) ($strip['pins'] ?? 0), 'whole' => (bool) ($strip['whole'] ?? false), 'cuts' => array_map('intval', is_array($strip['cuts'] ?? null) ? $strip['cuts'] : []), 'rest' => (int) ($strip['rest'] ?? 0), 'scrap' => (int) ($strip['scrap'] ?? 0)];
                }
            }

            $out[(int) $place] = ['opened' => (int) ($plan['opened'] ?? 0), 'strips' => $strips];
        }

        return $out;
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
    public function updateAssembly(int $order, int $assembly, array $pieces, array $unused, ?int $tested, ?int $done, ?string $note, string $today, ?int $sparte = null, ?float $minutes = null, ?int $place = null): string
    {
        $built = $this->order($order);
        $part  = $built->assembly($assembly) ?? throw ManufacturingError::assemblyNotOfOrder($assembly, $order);
        $list  = $this->assignedLists($built, $this->partsLists([$part->revision]))[$part->revision] ?? [];
        $all   = array_column($list, 'id');
        $fitted = Demand::boardsFromPieces($pieces, $list);
        // His word 2026-11-15: any position of the list may be left out, not only an option.
        $changes = (new OrderChanges($this->structure()))->updateAssembly($built, $assembly, $fitted, $unused, $all, $all, $tested, $done, $note, $today);
        $made    = $done === null ? 0 : $done - $part->done;
        $boards  = $this->boardsAfter($part, $fitted, $unused, $list);

        // D-1304/D-1342 replace D-1177: the test is a report at the board's lot on «Test», so «Fertig» only needs the board fully fitted.
        // D-1428: a kit is packed, not fitted — the box's parts are checked when it is booked.
        if (! $built->kit && ($done ?? $part->done) > $boards) {
            throw ManufacturingError::doneBeyondFitted($done ?? $part->done, $boards);
        }

        $booked  = 'nothing';
        $cost    = null;
        // D-1302: parts are used up when a board is finished, not when it is fitted; a half-fitted board keeps its parts in the box.
        $use     = Demand::finished($part, $made, $list, $unused);

        if ($use !== [] && has_filter(self::BOOK_FILTER)) {
            if ($built->box === null) {
                throw ManufacturingError::stockRefused(__('Dem Auftrag ist kein Gebinde zugeordnet.', 'taxmod-manufacturing'));
            }

            // His word 16.11.: the box serves several orders; when the parts are gone, say which, before anything is booked.
            $short = Demand::shortfall($use, $this->presentIn($built->box));

            if ($short !== []) {
                $names = $this->labelsOf(array_keys($short));
                $count = static fn (float $n): string => ListPage::number($n, 2);

                throw ManufacturingError::shortAtFinish(implode(', ', array_map(static fn (int $thing, float $n): string => $count($n) . ' × ' . ($names[$thing] ?? '#' . $thing), array_keys($short), $short)));
            }

            // D-1425: the price is read from the very lots the booking below takes, before it takes them.
            $cost   = $made > 0 && $minutes !== null ? $this->costPerBoard($built, $made, $use, $minutes, $today) : null;
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
                $this->bookOutput($built, $part->revision, $made, $today, $sparte, $cost, $place);
            } catch (ManufacturingError $error) {
                if ($booked === 'booked') {
                    // The consumption is already booked; give it back so the box shows what it did before. Review F12: a refused
                    // return is said, not swallowed — the box would show less than it holds.
                    $returned = apply_filters(self::BOOK_FILTER, null, ['reference' => $order, 'place' => $built->box, 'lines' => array_map(static fn (float $q): float => -$q, $use), 'title' => $built->label, 'date' => $today]);

                    if (is_wp_error($returned)) {
                        throw ManufacturingError::stockRefused($error->detail . ' ' . sprintf(__('Der Verbrauch ist schon gebucht und ließ sich nicht zurückbuchen (%s); bitte das Gebinde im Lager prüfen oder die Änderung rückgängig machen.', 'taxmod-manufacturing'), $returned->get_error_message()));
                    }
                }

                throw $error;
            }

            $booked = $booked === 'unbooked' ? 'unbooked' : 'booked';
        }

        if ($changes !== []) {
            $this->model->write($changes);
        }

        return $booked;
    }

    /**
     * Boards of each assembly that are fully fitted and not yet completed — what «Fertigung abschließen» may take (D-1419).
     *
     * @return array<int, int> assembly → boards
     */
    public function readyToFinish(BuildOrder $order): array
    {
        $lists = $this->assignedLists($order, $this->partsLists(array_map(static fn (Assembly $a): int => $a->revision, $order->assemblies)));
        $out   = [];

        foreach ($order->assemblies as $part) {
            // D-1428: a kit needs no fitting; what the box lacks, the booking names.
            $ready          = $order->kit ? $order->count - $part->done : min($this->boardsAfter($part, [], $part->unused, $lists[$part->revision] ?? []), $order->count) - $part->done;
            $out[$part->id] = max(0, $ready);
        }

        return $out;
    }

    /**
     * D-1419 «Fertigung abschließen»: n more boards of every assembly are completed — their parts used up (D-1302), the boards
     * untested onto «Test» (D-1303), the count «x von N abgeschlossen» raised; once all N are, the order goes to «Test». A
     * partial completion keeps the order, its box and its reservations for the rest (his word 17.11. 16:53). All of it is one
     * act, so one undo takes it back (D-1410).
     *
     * @param array<int, int> $categories assembly → Material-Kategorie, for a revision whose board material does not exist yet
     * @return array{act: ?int, step: string}
     */
    public function finishBuild(int $order, int $boards, string $today, array $categories, ?float $minutes = null, ?int $place = null): array
    {
        if (! method_exists(\Taxmod\WordPress\ModelDoor::class, 'inAct')) {
            throw ManufacturingError::noActBracket();
        }

        $built = $this->order($order);

        // D-1433: an order whose boards are partly in «Test» still finishes its remaining ones.
        if (! in_array($built->step, ['order_building', 'order_test'], true)) {
            throw ManufacturingError::unknownStatus('finish');
        }

        $ready = $this->readyToFinish($built);

        if ($boards < 1 || $boards > min($ready ?: [0])) {
            throw ManufacturingError::notReadyToFinish($boards, min($ready ?: [0]));
        }

        if ($this->canPrice() && ($minutes === null || $minutes < 0)) {
            throw ManufacturingError::notAnAmount('minutes', (string) $minutes);
        }

        // D-1428, INF-134 open: a kit goes where the dialog says — the production inbound or the order's box.
        if ($built->kit && ! in_array($place, array_keys($this->kitPlaces($built)), true)) {
            throw ManufacturingError::notConfigured('kit_place');
        }

        $answer = \Taxmod\WordPress\ModelDoor::inAct(function () use ($built, $boards, $today, $categories, $minutes, $place): string {
            foreach ($built->assemblies as $part) {
                $this->updateAssembly($built->id, $part->id, [], $part->unused, null, $part->done + $boards, null, $today, ($categories[$part->id] ?? 0) ?: null, $minutes, $built->kit ? $place : null);
            }

            // D-1475: the stopwatch's time is booked onto these boards — back to nothing, in this act.
            $reset = (new Stopwatch($this->structure(), $this->workedIsDuration()))->reset($built);

            if ($reset !== []) {
                $this->model->write($reset);
            }

            $after = $this->order($built->id);

            // D-1433 A: from «In Fertigung» on the step is what the boards say; nothing is written for it.
            return $this->derived([$after])[0]->step;
        });

        return ['act' => $answer['act'], 'step' => $answer['result']];
    }

    /** D-1425: the stock's filters of the price — the consumed lots' prices, and the frozen breakdown at a board's lot. */
    public const PRICES_FILTER = 'taxmod_stock_build_prices';
    public const COST_FILTER   = 'taxmod_stock_cost_breakdown';

    public function canPrice(): bool
    {
        return has_filter(self::PRICES_FILTER) && has_filter(self::BOOK_FILTER) && $this->canReceive();
    }

    /**
     * D-1425: the price of one of `$made` boards completed now. The set-up is spread over every board the order makes —
     * its count times its boards — so that all its completions carry it once.
     *
     * @param array<int, float> $use thing → pieces used up
     * @return array<string, mixed>|null null when the stock does not price yet
     */
    private function costPerBoard(BuildOrder $order, int $made, array $use, float $minutes, string $today): ?array
    {
        if (! $this->canPrice()) {
            return null;
        }

        $answer = apply_filters(self::PRICES_FILTER, null, ['reference' => $order->id, 'place' => $order->box, 'lines' => $use]);

        if (is_wp_error($answer) || ! is_array($answer)) {
            throw ManufacturingError::stockRefused(is_wp_error($answer) ? $answer->get_error_message() : __('Das Lager kennt die Preise der Teile nicht.', 'taxmod-manufacturing'));
        }

        $settings = \TaxmodManufacturing\WordPress\Admin\SettingsTab::settings();

        return Costing::perBoard($order->id, $today, $made, $use, (array) ($answer['parts'] ?? []), $minutes, $settings->wage, $settings->setupHours,
            max(1, $order->count * count($order->assemblies)), array_map('floatval', (array) ($answer['scrap'] ?? [])));
    }

    /**
     * D-1425: the minutes per board of the revision's last completion, to preset the dialog; null when there is none.
     */
    public function lastMinutes(int $revision, bool $kit = false): ?float
    {
        $material = has_filter(self::COST_FILTER) ? $this->existingMaterial($revision, $kit) : null;

        if ($material === null) {
            return null;
        }

        $answer = apply_filters(self::COST_FILTER, null, ['thing' => $material]);

        return is_array($answer) && is_numeric($answer['work']['minutes'] ?? null) ? (float) $answer['work']['minutes'] : null;
    }

    /**
     * D-1425: the frozen breakdowns of lots, as the stock keeps them.
     *
     * @param list<int> $lots
     * @return array<int, array<string, mixed>> lot → breakdown; lots without one are left out
     */
    public function costOf(array $lots): array
    {
        if ($lots === [] || ! has_filter(self::COST_FILTER)) {
            return [];
        }

        $answer = apply_filters(self::COST_FILTER, null, ['lots' => $lots]);

        return is_array($answer) ? array_filter($answer, 'is_array') : [];
    }

    /** D-1419, D-1420: the stock's filters of the step «Test» — read the order's boards, record a result, scrap whole lots. */
    public const TEST_LOTS_FILTER   = 'taxmod_stock_test_lots';
    public const TEST_RESULT_FILTER = 'taxmod_stock_test_result';
    public const SCRAP_LOTS_FILTER  = 'taxmod_stock_scrap_lots';

    public function canTest(): bool
    {
        return has_filter(self::TEST_LOTS_FILTER) && has_filter(self::TEST_RESULT_FILTER) && has_filter(self::SCRAP_LOTS_FILTER);
    }

    /**
     * The completed boards of an order as the stock holds them (D-1419): the lots booked in with the order as Bezug.
     *
     * @return array<int, array{thing: int, place: int, quantity: float, state: string, tested_on: string, note: string}> lot → its state
     */
    public function testLots(int $order): array
    {
        if (! has_filter(self::TEST_LOTS_FILTER)) {
            return [];
        }

        $answer = apply_filters(self::TEST_LOTS_FILTER, null, ['reference' => $order]);

        if (is_wp_error($answer)) {
            throw ManufacturingError::stockRefused($answer->get_error_message());
        }

        $out = [];

        foreach (is_array($answer) ? $answer : [] as $lot => $row) {
            $row = is_array($row) ? $row : [];
            $out[(int) $lot] = [
                'thing'     => (int) ($row['thing'] ?? 0),
                'place'     => (int) ($row['place'] ?? 0),
                'quantity'  => (float) ($row['quantity'] ?? 0),
                'state'     => (string) ($row['state'] ?? 'untested'),
                'tested_on' => (string) ($row['tested_on'] ?? ''),
                'note'      => (string) ($row['note'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The completed boards of many orders in one read of the stock (CD-7) — order → lot → its row. Empty for a stock that
     * cannot answer for several orders at once; the orders then keep their stored step.
     *
     * @param list<int> $orders
     * @return array<int, array<int, array{state: string}>>
     */
    public function testLotsOf(array $orders): array
    {
        $orders = array_values(array_unique(array_filter($orders)));

        if ($orders === [] || ! has_filter(self::TEST_LOTS_FILTER)) {
            return [];
        }

        $answer = apply_filters(self::TEST_LOTS_FILTER, null, ['references' => $orders]);

        if (is_wp_error($answer) || ! is_array($answer)) {
            return [];
        }

        $out = [];

        foreach ($orders as $order) {
            foreach (is_array($answer[$order] ?? null) ? $answer[$order] : [] as $lot => $row) {
                $out[$order][(int) $lot] = ['state' => (string) ((is_array($row) ? $row : [])['state'] ?? 'untested')];
            }
        }

        return $out;
    }

    /** @var array<int, bool> order → a board of it is in «Nacharbeit», as the last {@see derived()} read it */
    private array $rework = [];

    /** Prüftor 20.11. (3b): a board of the order is in «Nacharbeit»; known for the orders the last {@see derived()} read. */
    public function inRework(int $order): bool
    {
        return $this->rework[$order] ?? false;
    }

    /**
     * Stefan 20.11. 19:02 «nicht immer eintippen … die letzten 3 oder so»: the latest findings of the boards of every order,
     * each once, newest first — read from the stock's test lots when the page is drawn, kept nowhere else.
     *
     * @return list<string>
     */
    public function recentFindings(int $count = 5): array
    {
        if (! has_filter(self::TEST_LOTS_FILTER)) {
            return [];
        }

        $ids    = array_map(static fn (BuildOrder $o): int => $o->id, $this->orders());
        $answer = $ids === [] ? [] : apply_filters(self::TEST_LOTS_FILTER, null, ['references' => $ids]);
        $rows   = [];

        foreach (is_array($answer) ? $answer : [] as $lots) {
            foreach (is_array($lots) ? $lots : [] as $row) {
                $rows[] = ['note' => (string) (((array) $row)['note'] ?? ''), 'tested_on' => (string) (((array) $row)['tested_on'] ?? '')];
            }
        }

        return Findings::recent($rows, $count);
    }

    /**
     * D-1433 A: the orders with the step their boards give them, from «In Fertigung» on; the stored step is not rewritten.
     *
     * @param list<BuildOrder> $orders
     * @return list<BuildOrder>
     */
    public function derived(array $orders): array
    {
        $started = array_values(array_filter($orders, static fn (BuildOrder $o): bool => in_array($o->step, ['order_building', 'order_test', 'order_done'], true)));

        if ($started === []) {
            return $orders;
        }

        $lots = count($started) === 1 ? [$started[0]->id => $this->testLots($started[0]->id)] : $this->testLotsOf(array_map(static fn (BuildOrder $o): int => $o->id, $started));

        // Prüftor 20.11. (3b): which orders have a board in «Nacharbeit», from the same read — a view, not a status.
        foreach ($lots as $order => $rows) {
            $this->rework[(int) $order] = array_filter($rows, static fn (array $row): bool => Positions::ofLot($row['state']) === Positions::REWORK) !== [];
        }

        return array_map(static fn (BuildOrder $o): BuildOrder => Positions::derive($o, $lots[$o->id] ?? []), $orders);
    }

    /** D-1475: the order's stopwatch exists once the model has its two fields. */
    public function canTime(): bool
    {
        return (new Stopwatch($this->structure(), $this->workedIsDuration()))->exists();
    }

    /** D-1475: «Y-m-d H:i:s» in UTC — the stopwatch counts on the server, never in the browser. */
    public static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * D-1475: start or stop the stopwatch of an order; each is one change, taken back by its number.
     *
     * @return ?int the change number
     */
    public function timer(int $order, bool $start): ?int
    {
        $built = $this->derived([$this->order($order)])[0];
        $watch = new Stopwatch($this->structure(), $this->workedIsDuration());
        $now   = self::now();

        if ($start || ! $this->workedIsDuration()) {
            $done = $this->model->write($start ? $watch->start($built, $now) : $watch->stop($built, $now));

            return isset($done['act']) ? (int) $done['act'] : null;
        }

        // Feldkatalog 21.11.: the duration in seconds through the modeller, in one act with the stop, so one undo takes both back.
        $act = \Taxmod\WordPress\ModelDoor::inAct(function () use ($watch, $built, $now): void {
            $this->model->write($watch->stop($built, $now));
            $this->model->putAmount($built->id, $this->structure()->field('order_worked'), Stopwatch::minutes($built, $now) * 60);
        })['act'] ?? null;

        return $act === null ? null : (int) $act;
    }

    /** D-1464: the plan does not work — the «Fertig» order becomes «Fehlgeschlagen», with the finding. */
    public function markFailed(int $order, string $finding): void
    {
        $this->model->write((new OrderChanges($this->structure()))->markFailed($this->derived([$this->order($order)])[0], $finding));
    }

    /**
     * D-1463: the status of each revision, derived from all orders as their boards say — read when asked, never kept.
     *
     * @param list<int> $revisions
     * @return array<int, array{status: string, label: string}>
     */
    public function revisionStatuses(array $revisions): array
    {
        if ($revisions === []) {
            return [];
        }

        $words = [RevisionStatus::NEW => __('Neu', 'taxmod-manufacturing'), RevisionStatus::CONFIRMED => __('Bestätigt', 'taxmod-manufacturing'), RevisionStatus::REJECTED => __('Nicht bestätigt', 'taxmod-manufacturing')]
            + \TaxmodManufacturing\WordPress\Admin\OrdersPage::viewLabels();

        return array_map(static fn (string $status): array => ['status' => $status, 'label' => (string) ($words[$status] ?? $status)], RevisionStatus::ofEach($revisions, $this->derived($this->orders())));
    }

    /** @return array<int, list<int>> D-1460: the materials this request made, with the systems taken over */
    public function madeMaterials(): array
    {
        return $this->made;
    }

    /** D-1460: the plan's «Systeme» and the hardware's are both in the model. */
    public function canCopySystems(): bool
    {
        $s = $this->structure();

        return $s->hasField('board_systems') && $s->hasField('hardware_systems') && $s->hasNode('systems');
    }

    /** @return array<int, string> D-1460: the systems to choose from at any depth (XT, AT under PC), value id → name */
    public function systemChoices(): array
    {
        if (! $this->canCopySystems()) {
            return [];
        }

        $root = $this->structure()->node('systems');

        return array_map(static fn (array $row): string => $row['name'], array_filter($this->model->tree($root), static fn (array $row, int $id): bool => $id !== $root && ! $row['hidden'], ARRAY_FILTER_USE_BOTH));
    }

    /**
     * D-1460: what the plan of this revision names as its systems — the proposal of the first completion, never assumed.
     *
     * @return list<int>
     */
    public function planSystems(int $revision): array
    {
        if (! $this->canCopySystems()) {
            return [];
        }

        $board = $this->boardsOfRevisions([$revision])[$revision] ?? 0;

        return $board === 0 ? [] : Systems::kept(array_map('intval', $this->model->records([$board])->all($board, $this->structure()->field('board_systems'))), $this->systemChoices());
    }

    /** D-1464: «Fehlgeschlagen» is in the model (Teil 1b); before it the action is not offered. */
    public function canFail(): bool
    {
        return $this->structure()->hasValue('order_failed');
    }

    public const MOVE_LOTS_FILTER = 'taxmod_stock_move_lots';
    public const LOT_LABEL_FILTER = 'taxmod_stock_lot_label_button';

    /**
     * D-1433 C/D: boards passed — «Fertiggestellt» — and moved from «Test» to «Wareneingang Produktion», one act.
     *
     * @param list<int> $lots
     */
    public function passBoards(int $order, array $lots, string $today): int
    {
        $inbound = has_filter('taxmod_stock_production_inbound') ? (int) apply_filters('taxmod_stock_production_inbound', null) : 0;

        if ($inbound <= 0) {
            throw ManufacturingError::noProductionInbound();
        }

        return $this->moveLots($order, $this->ownLots($order, $lots), $inbound, 'passed', '', $today);
    }

    /**
     * D-1433 D: «Fertigstellen» — every open board (in test or in rework) passed and moved, one act.
     */
    public function finishAll(int $order, string $today): int
    {
        $open = array_keys(array_filter($this->testLots($order), static fn (array $lot): bool => in_array(Positions::ofLot($lot['state']), [Positions::TEST, Positions::REWORK], true)));

        if ($open === []) {
            throw ManufacturingError::nothingSelected();
        }

        return $this->passBoards($order, $open, $today);
    }

    /**
     * D-1433 D, architecture 18.11.: «Zurück in Fertigung» takes the finished boards back onto «Test», untested; before the
     * completion only the undo goes (D-1410).
     */
    public function backToTest(int $order, string $today): int
    {
        $place = has_filter(self::TEST_PLACE_FILTER) ? (int) apply_filters(self::TEST_PLACE_FILTER, null) : 0;
        $done  = array_keys(array_filter($this->testLots($order), static fn (array $lot): bool => Positions::ofLot($lot['state']) === Positions::DONE));

        if ($place <= 0) {
            throw ManufacturingError::notConfigured('test_place');
        }

        if ($done === []) {
            throw ManufacturingError::nothingSelected();
        }

        return $this->moveLots($order, $done, $place, 'untested', __('Auftrag zurück in Bau', 'taxmod-manufacturing'), $today);
    }

    /**
     * D-1433 B: rework done, the boards back in «Test» — untested again, the note as Befund.
     *
     * @param list<int> $lots
     */
    public function retest(int $order, array $lots, string $note, string $today): int
    {
        return $this->recordTest($order, $lots, 'untested', $note, $today);
    }

    /** @param list<int> $lots */
    private function moveLots(int $order, array $lots, int $place, string $result, string $note, string $today): int
    {
        $answer = apply_filters(self::MOVE_LOTS_FILTER, null, ['lots' => $lots, 'place' => $place, 'date' => $today, 'title' => $this->order($order)->label, 'reference' => $order, 'result' => $result, 'note' => $note]);

        if (is_wp_error($answer) || ! is_int($answer)) {
            throw ManufacturingError::stockRefused(is_wp_error($answer) ? $answer->get_error_message() : __('Das Lager hat nicht geantwortet.', 'taxmod-manufacturing'));
        }

        return $answer;
    }

    /** D-1433 E: the stock's own label button of a board, printed only when pressed (his rule 15.11.); '' without it. */
    public function lotLabelButton(int $lot): string
    {
        $button = has_filter(self::LOT_LABEL_FILTER) ? apply_filters(self::LOT_LABEL_FILTER, '', ['lot' => $lot]) : '';

        return is_string($button) ? $button : '';
    }

    /**
     * D-1419 «Test bestanden» / «Test nicht bestanden …» on the ticked boards; the stock writes the test report, one act.
     *
     * @param list<int> $lots
     * @param 'passed'|'failed'|'untested' $result
     */
    public function recordTest(int $order, array $lots, string $result, string $note, string $today): int
    {
        $answer = apply_filters(self::TEST_RESULT_FILTER, null, ['lots' => $this->ownLots($order, $lots), 'result' => $result, 'note' => $note, 'date' => $today]);

        if (is_wp_error($answer) || ! is_int($answer)) {
            throw ManufacturingError::stockRefused(is_wp_error($answer) ? $answer->get_error_message() : __('Das Lager hat nicht geantwortet.', 'taxmod-manufacturing'));
        }

        return $answer;
    }

    /**
     * D-1419 «Verschrotten …»: the ticked boards leave whole as «Defekt/Ausschuss» with the order as Bezug (D-1420).
     *
     * @param list<int> $lots
     */
    public function scrapBoards(int $order, array $lots, string $reason, string $today): int
    {
        $built  = $this->order($order);
        $answer = apply_filters(self::SCRAP_LOTS_FILTER, null, ['lots' => $this->ownLots($order, $lots), 'reference' => $order, 'reason' => $reason, 'title' => $built->label, 'date' => $today]);

        if (is_wp_error($answer) || ! is_int($answer)) {
            throw ManufacturingError::stockRefused(is_wp_error($answer) ? $answer->get_error_message() : __('Das Lager hat nicht geantwortet.', 'taxmod-manufacturing'));
        }

        return $answer;
    }

    /**
     * Only boards of this order are acted on, whatever the form sent.
     *
     * @param list<int> $lots
     * @return list<int>
     */
    private function ownLots(int $order, array $lots): array
    {
        $own = array_values(array_intersect(array_map('intval', $lots), array_keys($this->testLots($order))));

        if ($own === []) {
            throw ManufacturingError::nothingSelected();
        }

        return $own;
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

        // Live-Check 21.11. (88226): nothing to fit is fitted on every board (package assumption §4, 7); callers cap at the count.
        return $counts === [] ? PHP_INT_MAX : min($counts);
    }

    /**
     * Finished boards as their own material (D-1143), untested, one lot per piece, onto the stock's place «Test» when one is
     * set, else into the order's box (D-1342); fewer finished give them back.
     */
    /**
     * @param array<string, mixed>|null $cost D-1425: the breakdown per board, the stock freezes it at each new lot
     * @param int|null $kitPlace D-1428: where packed kits go, chosen in the dialog
     */
    private function bookOutput(BuildOrder $order, int $revision, int $boards, string $today, ?int $sparte, ?array $cost = null, ?int $kitPlace = null): void
    {
        $test  = $order->kit ? 0 : (has_filter(self::TEST_PLACE_FILTER) ? (int) apply_filters(self::TEST_PLACE_FILTER, null) : 0);
        $place = $order->kit ? $kitPlace : ($test > 0 ? $test : $order->box);

        if ($place === null) {
            throw ManufacturingError::stockRefused(__('Dem Auftrag ist kein Gebinde zugeordnet.', 'taxmod-manufacturing'));
        }

        $answer = apply_filters(self::OUTPUT_FILTER, null, ['reference' => $order->id, 'place' => $place, 'lines' => [$this->materialFor($revision, $sparte, $order->kit) => (float) $boards], 'title' => $order->label, 'date' => $today]
            // D-1428: a kit is not tested — one lot, ready for sale.
            + ($order->kit ? ['tested' => true] : [])
            + ($cost === null ? [] : ['cost' => $cost]));

        if (is_wp_error($answer)) {
            throw ManufacturingError::stockRefused($answer->get_error_message());
        }
    }

    /**
     * The material «assembled board» of a revision (D-1261): the hardware record whose «Revision» names it. Made at the first
     * finished board in the Sparte chosen then — the Sparte is where the record lies, nothing else stores it.
     * D-1460: the plan's «Systeme» are copied into the new record once; an existing record is left as it is.
     */
    public function materialFor(int $revision, ?int $sparte = null, bool $kit = false): int
    {
        $existing = $this->existingMaterial($revision, $kit);

        if ($existing !== null) {
            return $existing;
        }

        $s = $this->structure();

        if ($sparte === null || $sparte <= 0) {
            throw ManufacturingError::sparteNeeded($revision);
        }

        // D-1176: the revision's name with «bestückt»; every other field stays empty until reviewed (D-1250, D-1255).
        $base   = $this->labelsOf([$revision])[$revision] ?? '#' . $revision;
        $name   = $kit ? sprintf(__('%s, Kit', 'taxmod-manufacturing'), $base) : sprintf(__('%s, bestückt', 'taxmod-manufacturing'), $base);
        $answer = apply_filters(self::CREATE_THING_FILTER, null, ['name' => $name, 'group' => $sparte]);

        if (is_wp_error($answer) || ! is_int($answer)) {
            throw ManufacturingError::stockRefused(is_wp_error($answer) ? $answer->get_error_message() : __('Das Lager hat kein Material angelegt.', 'taxmod-manufacturing'));
        }

        $values = [(string) $s->field('assembled_revision') => (string) $revision];

        // D-1460: nothing inherited, nothing kept in step — the plan's value, once; empty stays empty. A kit the same: it is
        // the same putting together, in a bag instead of soldered (his word 19.11. 07:01).
        $systems = $this->planSystems($revision);

        if ($systems !== []) {
            $values[(string) $s->field('hardware_systems')] = ['values' => array_map('strval', $systems)];
        }

        if ($this->canCopySystems()) {
            $this->made[$answer] = $systems;
        }

        // D-1428: the art tells kit and assembled board apart, never the name.
        if ($s->hasField('assembled_art') && $s->hasValue($kit ? 'art_kit' : 'art_fitting')) {
            $values[(string) $s->field('assembled_art')] = (string) $s->value($kit ? 'art_kit' : 'art_fitting');
        }

        $this->model->write([['op' => 'set_values', 'record' => (string) $answer, 'values' => $values]]);
        $this->model->markForReview([$answer]);

        return $answer;
    }

    /** D-1428: the kit and the assembled board of a revision are told apart by their art; one without art is assembled. */
    public function existingMaterial(int $revision, bool $kit = false): ?int
    {
        $s     = $this->structure();
        $found = $this->model->referrers([$revision], $s->field('assembled_revision'));

        if ($found !== [] && $s->hasField('assembled_art') && $s->hasValue('art_kit')) {
            $arts  = $this->model->records($found);
            $found = array_values(array_filter($found, static fn (int $thing): bool => ((int) ($arts->first($thing, $s->field('assembled_art')) ?? 0) === $s->value('art_kit')) === $kit));
        } elseif ($kit) {
            return null;
        }

        return $found === [] ? null : min($found);
    }

    /** D-1428: whether this site's model knows the art «Kit» at order and material. */
    public function canKit(): bool
    {
        $s = $this->structure();

        return $s->hasField('order_art') && $s->hasField('assembled_art') && $s->hasValue('art_kit') && $s->hasValue('art_fitting');
    }

    /**
     * D-1428, INF-134 open: where packed kits may go until he decides — the production inbound, else the order's box.
     *
     * @return array<int, string> place → its name
     */
    public function kitPlaces(BuildOrder $order): array
    {
        $inbound = has_filter('taxmod_stock_production_inbound') ? (int) apply_filters('taxmod_stock_production_inbound', null) : 0;
        $places  = array_values(array_filter([$inbound, (int) $order->box]));

        $names   = $places === [] ? [] : $this->labelsOf($places);

        return array_combine($places, array_map(static fn (int $place): string => $names[$place] ?? '#' . $place, $places));
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
     * One stock service per request (review F1): its cache hangs on the object, so a new one per call never hits it. Like the
     * stock's own reader it is renewed once the stock has written since.
     */
    private function stock(): \TaxmodStock\WordPress\StockService
    {
        $gateway = \TaxmodStock\WordPress\ModelGateway::class;
        $writes  = class_exists($gateway) && property_exists($gateway, 'writes') ? (int) $gateway::$writes : 0;

        if (self::$stock === null || self::$stockReadAt !== $writes) {
            self::$stock       = new \TaxmodStock\WordPress\StockService();
            self::$stockReadAt = $writes;
        }

        return self::$stock;
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

        return array_map('floatval', $this->stock()->presentIn($box));
    }

    /**
     * What lies in each of these boxes, for a list of orders. One ledger and one place tree for all boxes, not one stock read
     * per order (CD-7): the stock module's own batch where it has one, else its ledger counted once per box.
     *
     * @param list<int> $boxes
     * @return array<int, array<int, float>> box → thing → pieces
     */
    public function presentInEach(array $boxes): array
    {
        $boxes = array_values(array_unique(array_filter($boxes)));
        $stock = \TaxmodStock\WordPress\StockService::class;

        if ($boxes === [] || ! class_exists($stock)) {
            return [];
        }

        $service = $this->stock();

        if (method_exists($service, 'presentInEach')) {
            return array_map(static fn (array $things): array => array_map('floatval', $things), (array) $service->presentInEach($boxes));
        }

        $bill = \TaxmodStock\Core\ProjectBill::class;

        if (! method_exists($service, 'ledger') || ! method_exists($service, 'locationTree') || ! class_exists($bill)) {
            return array_combine($boxes, array_map(fn (int $box): array => $this->presentIn($box), $boxes));
        }

        $ledger = $service->ledger();
        $tree   = $service->locationTree();
        $out    = [];

        foreach ($boxes as $box) {
            $out[$box] = array_map('floatval', $bill::present($ledger, $tree->withAllBelow($box)));
        }

        return $out;
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
        $built = $this->order($order);

        // D-1419: out of «Test» only once every completed board has passed or is scrapped.
        if ($to === 'order_done' && $built->step === 'order_test' && ! $built->testsSettled(array_column($this->testLots($order), 'state'))) {
            throw ManufacturingError::testsOpen($order);
        }

        $this->model->write((new OrderChanges($this->structure()))->setStep($built, $to, $today, $missing, $reason));
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
        $out    = [];
        $all    = array_merge(...array_map(fn (int $r): array => $this->positionsOf($r), $revisions) ?: [[]]);
        $partOf = [];

        foreach ($all as $position) {
            $partOf[$position] = (int) $positions->first($position, $s->field('position_part'));
        }

        // D-1566: a part that already has the position's pins is a cut strip; it counts in pieces.
        $poles  = Demand::withoutCutStrips($this->piecePins($all), $partOf, $this->partPins(array_values(array_unique(array_filter($partOf)))));

        foreach ($revisions as $revision) {
            foreach ($this->positionsOf($revision) as $position) {
                $piece            = $poles[$position] ?? 0;
                $out[$revision][] = [
                    'id'      => $position,
                    'thing'   => (int) $positions->first($position, $s->field('position_part')),
                    // D-1398: a line with «Polzahl» counts pieces of a strip counted in pins; the need is in pins.
                    'count'   => (float) ($positions->first($position, $s->field('position_count')) ?? 1) * ($piece > 0 ? $piece : 1),
                    'piece'   => $piece,
                    'fitting' => $s->hasField('position_fitting') ? $fitting($positions->first($position, $s->field('position_fitting'))) : '',
                    'ref'     => $s->hasField('position_ref') ? implode(', ', $positions->all($position, $s->field('position_ref'))) : '',
                ];
            }
        }

        $loss = $this->cutLoss(array_values(array_unique(array_merge(...array_map(static fn (array $l): array => array_column($l, 'thing'), array_values($out)) ?: [[]]))));

        return $loss === [] ? $out : array_map(static fn (array $list): array => Demand::withCutLoss($list, $loss), $out);
    }

    /**
     * D-1566: the pins of each part itself — its «Polzahl», a unit value, read with the parts and their amounts in two reads (CD-7).
     *
     * @param list<int> $parts
     * @return array<int, int> part → pins, only where set
     */
    private function partPins(array $parts): array
    {
        $s = $this->structure();

        if ($parts === [] || ! $s->hasField('part_poles') || ! $s->hasField('unit_amount')) {
            return [];
        }

        $table  = $this->model->records($parts);
        $values = [];

        foreach ($parts as $part) {
            $value = (int) $table->first($part, $s->field('part_poles'));

            if ($value > 0) {
                $values[$part] = $value;
            }
        }

        $amounts = $values === [] ? null : $this->model->records(array_values(array_unique($values)));
        $out     = [];

        foreach ($values as $part => $value) {
            $pins = (int) round((float) str_replace(',', '.', (string) $amounts?->first($value, $s->field('unit_amount'))));

            if ($pins > 0) {
                $out[$part] = $pins;
            }
        }

        return $out;
    }

    /**
     * D-1393/D-1398: the pins of one piece at these BOM lines — «Polzahl» is a unit value, read in one go (CD-7).
     *
     * @param list<int> $positions
     * @return array<int, int> position → pins per piece, only where set
     */
    private function piecePins(array $positions): array
    {
        $s = $this->structure();

        if ($positions === [] || ! $s->hasField('position_poles') || ! $s->hasField('unit_amount')) {
            return [];
        }

        $table  = $this->table('bom_position');
        $values = [];

        foreach ($positions as $position) {
            $value = (int) $table->first($position, $s->field('position_poles'));

            if ($value > 0) {
                $values[$position] = $value;
            }
        }

        if ($values === []) {
            return [];
        }

        $amounts = $this->model->records(array_values(array_unique($values)));
        $out     = [];

        foreach ($values as $position => $value) {
            $pins = (int) round((float) str_replace(',', '.', (string) $amounts->first($value, $s->field('unit_amount'))));

            if ($pins > 0) {
                $out[$position] = $pins;
            }
        }

        return $out;
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

        // Review F3: only the stock records of these materials, not the whole stock node (D-1195).
        $wanted = array_flip($things);
        $table  = $this->model->records($this->model->referrers($things, $s->field('lager_thing')));
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
        $field  = $this->structure()->field('board_revisions');
        $boards = $this->model->records($this->model->referrers($revisions, $field));
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

    /**
     * D-1373: every revision of the board a revision belongs to, for changing it while the order is «Angelegt».
     *
     * @return array<int, string> revision id → label
     */
    public function siblingRevisions(int $revision): array
    {
        $board = $this->boardsOfRevisions([$revision])[$revision] ?? 0;

        if ($board === 0) {
            return [];
        }

        $ids = array_map('intval', $this->table('board')->all($board, $this->structure()->field('board_revisions')));

        return $ids === [] ? [] : $this->labelsOf($ids);
    }

    /**
     * Stefan 21.11. 10:34: the plans an order may build — every revision of every board of its project, one row per board for
     * the standard chooser (D-1488); the tables are read once, the labels in one read (CD-7).
     *
     * @return list<array{depth: int, name: string, records: array<int, string>}>
     */
    public function planTree(int $project): array
    {
        $s       = $this->structure();
        $boardT  = $this->table('board');
        $boards  = array_map('intval', $this->table('project')->all($project, $s->field('project_boards')));
        $revs    = [];

        foreach ($boards as $board) {
            $revs[$board] = array_map('intval', $boardT->all($board, $s->field('board_revisions')));
        }

        $labels = $this->labelsOf([...$boards, ...array_merge([], ...array_values($revs))]);
        $rows   = [];

        foreach ($revs as $board => $ids) {
            $records = [];

            foreach ($ids as $rev) {
                $records[$rev] = ($labels[$board] ?? '') . ' · ' . ($labels[$rev] ?? '#' . $rev);
            }

            $rows[] = ['depth' => 0, 'name' => $labels[$board] ?? '#' . $board, 'records' => $records];
        }

        return $rows;
    }

    /**
     * Stefan 21.11. 10:34: the order builds these plans (see {@see OrderChanges::setBoards()}). Prüftor 21.11.: an order holding
     * reservations does not change its plans; they are given free first, never silently.
     *
     * @param list<int> $revisions
     */
    public function setBoards(int $order, array $revisions): void
    {
        $built = $this->order($order);
        $have  = array_map(static fn (Assembly $a): int => $a->revision, $built->assemblies);

        if (array_diff($have, $revisions) === [] && array_diff($revisions, $have) === []) {
            return;
        }

        if (array_diff($have, $revisions) !== [] && $this->reservationRows($order) !== []) {
            throw ManufacturingError::boardsReserved($order);
        }

        $this->model->write((new OrderChanges($this->structure()))->setBoards($built, $revisions, $this->boardsOfRevisions(array_values(array_unique([...$have, ...$revisions])))));
    }

    /** D-1300: count in any step before the order is closed, revision while it is «Angelegt» (D-1373). */
    public function setHead(int $order, string $count, ?int $revision, ?string $due = null): void
    {
        $this->model->write((new OrderChanges($this->structure()))->setHead($this->order($order), $count, $revision, $due));
    }

    public const RELEASE_FILTER = 'taxmod_stock_release';

    /**
     * D-1300: after the count fell, the reservations beyond what is left to fit are given free in one Storno; what was moved
     * into the box stays until «Box leer räumen».
     *
     * @param array<int, list<array<string, mixed>>> $lists revision → positions, with what is assigned to them
     * @return int how many reservation lines were released
     */
    public function releaseSurplus(BuildOrder $order, array $lists): int
    {
        if (! has_filter(self::RESERVATIONS_FILTER) || ! has_filter(self::RELEASE_FILTER)) {
            return 0;
        }

        $answer = apply_filters(self::RESERVATIONS_FILTER, null, ['subjects' => [$order->id]]);
        $lines  = [];

        foreach (is_array($answer[$order->id] ?? null) ? $answer[$order->id] : [] as $line) {
            if (is_array($line) && (int) ($line['line'] ?? 0) > 0 && (int) ($line['thing'] ?? 0) > 0) {
                $lines[] = ['line' => (int) $line['line'], 'thing' => (int) $line['thing'], 'quantity' => (float) ($line['quantity'] ?? 0)];
            }
        }

        $box     = $this->presentIn($order->box);
        $release = Demand::surplusReservations(Demand::of($order->assemblies, $lists, $order->count, $box), $box, $lines);

        if ($release === []) {
            return 0;
        }

        $done = apply_filters(self::RELEASE_FILTER, null, ['subject' => $order->id, 'lines' => $release]);
        $this->reservationLines = [];

        if (is_wp_error($done)) {
            throw ManufacturingError::stockRefused($done->get_error_message());
        }

        return count($release);
    }

    /** D-1373: one step back, until the first fitted board. */
    public function stepBack(int $order): void
    {
        $this->model->write((new OrderChanges($this->structure()))->stepBack($this->order($order)));
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

    /**
     * Stefan 26.11. «A»: the revisions of every plan of the given projects, for the column «Revisionen» and the list under
     * Fertigung › «Revisionen». The tables are read once, the labels in one read (CD-7).
     *
     * @param list<int> $projects
     * @return array<int, list<array{board: int, board_label: string, revision: int, revision_label: string}>> project → revisions in plan order
     */
    public function revisionsOfProjects(array $projects): array
    {
        $s      = $this->structure();
        $projT  = $this->table('project');
        $boardT = $this->table('board');
        $plans  = [];
        $ids    = [];

        foreach ($projects as $project) {
            foreach (array_map('intval', $projT->all($project, $s->field('project_boards'))) as $board) {
                $revisions               = array_map('intval', $boardT->all($board, $s->field('board_revisions')));
                $plans[$project][$board] = $revisions;
                array_push($ids, $board, ...$revisions);
            }
        }

        $labels = $ids === [] ? [] : $this->labelsOf($ids);
        $out    = [];

        foreach ($plans as $project => $boards) {
            foreach ($boards as $board => $revisions) {
                foreach ($revisions as $revision) {
                    $out[$project][] = ['board' => $board, 'board_label' => $labels[$board] ?? '#' . $board, 'revision' => $revision, 'revision_label' => $labels[$revision] ?? '#' . $revision];
                }
            }
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
