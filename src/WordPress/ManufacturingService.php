<?php declare(strict_types=1);

namespace TaxmodManufacturing\WordPress;

use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\BuildOrders;
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
        return (new BuildOrders($this->structure()))->all($this->table('order'), $this->table('assembly'));
    }

    public function order(int $id): BuildOrder
    {
        return (new BuildOrders($this->structure()))->one($id, $this->table('order'), $this->table('assembly'));
    }

    /** @return int the new order's id */
    public function create(int $project, string $count, ?int $box, string $title): int
    {
        $changes   = new OrderChanges($this->structure());
        $revisions = $changes->revisionsOf($project, $this->table('project'), $this->table('board'));
        $answer    = $this->model->write($changes->create($project, $revisions, $count, $box, $title));

        return (int) ($answer['refs']['auftrag'] ?? 0);
    }

    /** @param list<int> $open */
    public function updateAssembly(int $order, int $assembly, string $step, array $open, string $today, ?string $note = null): void
    {
        $built = $this->order($order);
        $part  = $built->assembly($assembly);

        $this->model->write((new OrderChanges($this->structure()))->updateAssembly($built, $assembly, $step, $open, $part === null ? [] : $this->positionsOf($part->revision), $today, $note));
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
     * The positions with the part they name and how many, for the assemblies shown — one read of the positions, one of their parts.
     *
     * @param list<int> $revisions
     * @return array<int, list<array{id: int, part: string, count: string}>> revision → positions
     */
    public function partsLists(array $revisions): array
    {
        $s         = $this->structure();
        $positions = $this->table('bom_position');
        $out       = [];
        $parts     = [];

        foreach ($revisions as $revision) {
            foreach ($this->positionsOf($revision) as $position) {
                $part = (int) $positions->first($position, $s->field('position_part'));
                $parts[$part] = true;
                $out[$revision][] = ['id' => $position, 'part' => (string) $part, 'count' => (string) ($positions->first($position, $s->field('position_count')) ?? '1')];
            }
        }

        $labels = $this->labelsOf(array_keys(array_filter($parts, static fn (bool $_, int $id): bool => $id > 0, ARRAY_FILTER_USE_BOTH)));

        foreach ($out as $revision => $rows) {
            foreach ($rows as $i => $row) {
                $out[$revision][$i]['part'] = $labels[(int) $row['part']] ?? ((int) $row['part'] > 0 ? '#' . $row['part'] : '—');
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
