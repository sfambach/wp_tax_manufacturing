<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * Reads the build orders, their assemblies and the assemblies' fitted counts out of three tables (one read per node, CD-7).
 *
 * ```mermaid
 * flowchart LR
 *   O["orders"] -->|order_assemblies: part ids| A["assemblies"]
 *   A -->|assembly_fitted: part ids| F["fitted: position → boards"]
 * ```
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/fertigung/konzept.md §2
 */
final class BuildOrders
{
    public function __construct(private readonly Structure $s)
    {
    }

    /** @return list<BuildOrder> newest first */
    public function all(Table $orders, Table $assemblies, Table $fittings): array
    {
        $out = [];

        foreach ($orders->ids() as $id) {
            $out[] = $this->one($id, $orders, $assemblies, $fittings);
        }

        usort($out, static fn (BuildOrder $a, BuildOrder $b): int => $b->id <=> $a->id);

        return $out;
    }

    public function one(int $id, Table $orders, Table $assemblies, Table $fittings): BuildOrder
    {
        $s = $this->s;

        if (! $orders->has($id)) {
            throw ManufacturingError::unknownOrder($id);
        }

        $count    = static fn (Table $t, int $record, string $key): int => $s->hasField($key) ? max(0, (int) ($t->first($record, $s->field($key)) ?? 0)) : 0;
        $parts    = [];

        foreach ($orders->all($id, $s->field('order_assemblies')) as $part) {
            $part   = (int) $part;
            $fitted = [];

            foreach ($assemblies->all($part, $s->field('assembly_fitted')) as $fitting) {
                $position = (int) $fittings->first((int) $fitting, $s->field('fitting_position'));

                if ($position > 0) {
                    $fitted[$position] = ['part' => (int) $fitting, 'boards' => $count($fittings, (int) $fitting, 'fitting_boards')];
                }
            }

            $parts[] = new Assembly(
                $part,
                (int) $assemblies->first($part, $s->field('assembly_revision')),
                $fitted,
                array_map('intval', $assemblies->all($part, $s->field('assembly_unused'))),
                $count($assemblies, $part, 'assembly_tested'),
                $count($assemblies, $part, 'assembly_done'),
                $s->hasField('assembly_note') ? (string) ($assemblies->first($part, $s->field('assembly_note')) ?? '') : '',
            );
        }

        $optional = static fn (string $key): ?string => $s->hasField($key) ? $orders->first($id, $s->field($key)) : null;
        $box      = (int) ($optional('order_box') ?? 0);

        return new BuildOrder(
            $id,
            (string) ($orders->first($id, $s->field('label')) ?? '#' . $id),
            (int) $orders->first($id, $s->field('order_project')),
            max(1, (int) ($orders->first($id, $s->field('order_count')) ?? 1)),
            $s->stepOf($orders->first($id, $s->field('order_status')), Structure::PROCESS) ?? 'order_planned',
            $box > 0 ? $box : null,
            $optional('order_started'),
            $optional('order_finished'),
            $parts,
            (string) ($optional('order_shortage_reason') ?? ''),
        );
    }
}
