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

    /** @return int the new order's id */
    public function create(int $project, string $count, ?int $box, string $title): int
    {
        $changes   = new OrderChanges($this->structure());
        $revisions = $changes->revisionsOf($project, $this->table('project'), $this->table('board'));
        $answer    = $this->model->write($changes->create($project, $revisions, $count, $box, $title));

        return (int) ($answer['refs']['auftrag'] ?? 0);
    }

    /** Filter of the stock module that books a build's consumption (D-1146); without it nothing is booked. */
    public const BOOK_FILTER = 'taxmod_stock_book_build';
    public const PLACES_FILTER = 'taxmod_stock_places_of';
    public const OUTPUT_FILTER = 'taxmod_stock_book_output';
    public const CREATE_THING_FILTER = 'taxmod_stock_create_thing';
    public const PICK_FILTER   = 'taxmod_stock_book_pick';

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
    public function updateAssembly(int $order, int $assembly, array $pieces, array $unused, ?int $tested, ?int $done, ?string $note, string $today): string
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
        $use     = Demand::consumption($part, $fitted, $list);

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
                $this->bookOutput($built, $part->revision, $made, $today);
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

        return $s->hasNode('assembled') && $s->hasField('assembled_revision') && has_filter(self::OUTPUT_FILTER) && has_filter(self::CREATE_THING_FILTER);
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
    private function bookOutput(BuildOrder $order, int $revision, int $boards, string $today): void
    {
        if ($order->box === null) {
            throw ManufacturingError::stockRefused(__('Dem Auftrag ist kein Gebinde zugeordnet.', 'taxmod-manufacturing'));
        }

        $answer = apply_filters(self::OUTPUT_FILTER, null, ['reference' => $order->id, 'place' => $order->box, 'lines' => [$this->materialFor($revision) => (float) $boards], 'title' => $order->label, 'date' => $today, 'tested' => true]);

        if (is_wp_error($answer)) {
            throw ManufacturingError::stockRefused($answer->get_error_message());
        }
    }

    /** The material «assembled board» of a revision; made at the first finished board. */
    public function materialFor(int $revision): int
    {
        $s     = $this->structure();
        $table = $this->table('assembled');
        $link  = $s->field('assembled_revision');

        foreach ($table->ids() as $id) {
            if ((int) $table->first($id, $link) === $revision) {
                return $id;
            }
        }

        $name   = sprintf(__('%s, bestückt', 'taxmod-manufacturing'), $this->labelsOf([$revision])[$revision] ?? '#' . $revision);
        $answer = apply_filters(self::CREATE_THING_FILTER, null, ['name' => $name, 'group' => $s->node('assembled')]);

        if (is_wp_error($answer) || ! is_int($answer)) {
            throw ManufacturingError::stockRefused(is_wp_error($answer) ? $answer->get_error_message() : __('Das Lager hat kein Material angelegt.', 'taxmod-manufacturing'));
        }

        $this->model->write([['op' => 'set_values', 'record' => (string) $answer, 'values' => [(string) $link => (string) $revision]]]);

        return $answer;
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

    /** @param 'order_done'|'order_cancelled'|'reopen' $to */
    public function setStep(int $order, string $to, string $today): void
    {
        $this->model->write((new OrderChanges($this->structure()))->setStep($this->order($order), $to, $today));
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

        return $out;
    }

    /**
     * @param list<int> $records
     * @return array<int, string> record id → its label
     */
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
