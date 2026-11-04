<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * Reads the build orders and their assemblies out of two tables (one read per node, CD-7).
 *
 * ```mermaid
 * flowchart LR
 *   O["orders table"] -->|order_assemblies: part ids| A["assemblies table"]
 *   A --> R["revision · step · open positions"]
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
    public function all(Table $orders, Table $assemblies): array
    {
        $out = [];

        foreach ($orders->ids() as $id) {
            $out[] = $this->one($id, $orders, $assemblies);
        }

        usort($out, static fn (BuildOrder $a, BuildOrder $b): int => $b->id <=> $a->id);

        return $out;
    }

    public function one(int $id, Table $orders, Table $assemblies): BuildOrder
    {
        $s = $this->s;

        if (! $orders->has($id)) {
            throw ManufacturingError::unknownOrder($id);
        }

        $parts = [];

        foreach ($orders->all($id, $s->field('order_assemblies')) as $part) {
            $part    = (int) $part;
            $parts[] = new Assembly(
                $part,
                (int) $assemblies->first($part, $s->field('assembly_revision')),
                $s->stepOf($assemblies->first($part, $s->field('assembly_status')), Structure::ASSEMBLY_STEPS) ?? 'build_open',
                array_map('intval', $assemblies->all($part, $s->field('assembly_unused'))),
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
            $s->stepOf($orders->first($id, $s->field('order_status')), Structure::ORDER_STEPS) ?? 'order_planned',
            $box > 0 ? $box : null,
            $optional('order_started'),
            $optional('order_finished'),
            $parts,
        );
    }
}
