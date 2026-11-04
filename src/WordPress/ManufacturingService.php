<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress;

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

    /**
     * Saves an assembly's counts. The consumption they mean is booked first, at the stock module, so a box that does not
     * hold enough stops the save; then the counts are written.
     *
     * @param array<int, int> $fitted position → boards
     * @param list<int>       $unused
     * @return 'booked'|'nothing'|'unbooked' unbooked: there was something to book, but the stock module offers no interface yet
     */
    public function updateAssembly(int $order, int $assembly, array $fitted, array $unused, ?int $tested, ?int $done, ?string $note, string $today): string
    {
        $built = $this->order($order);
        $part  = $built->assembly($assembly) ?? throw ManufacturingError::assemblyNotOfOrder($assembly, $order);
        $list  = $this->partsLists([$part->revision])[$part->revision] ?? [];
        $all   = array_column($list, 'id');
        // Only an option may be left out (his word: «bei optionalen … was nicht verwendet wird»).
        $options = $this->structure()->hasValue('fitting_optional') ? array_column(array_filter($list, static fn (array $p): bool => $p['fitting'] === 'optional'), 'id') : $all;
        $changes = (new OrderChanges($this->structure()))->updateAssembly($built, $assembly, $fitted, $unused, $all, $options, $tested, $done, $note, $today);
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

        $this->model->write($changes);

        return $booked;
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
