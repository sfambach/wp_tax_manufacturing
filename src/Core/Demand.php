<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * What a build order needs, board by board, set against what lies in its box (his word 2026-11-04: «dort alle Positionen
 * … von Bauteilen bis hin zur Platine in der benötigten Menge», «alles was auf dem Platz liegt ist vorhanden»).
 *
 * ```mermaid
 * flowchart LR
 *   A["assembly: revision"] --> B["board itself × pieces"]
 *   A --> P["positions: count × pieces<br/>without «nein» and unused options"]
 *   X["box and the places in it"] --> S["thing → pieces there"]
 *   B --> C["needed · there · missing"]
 *   P --> C
 *   S --> C
 * ```
 *
 * What lies in the box is handed out in the order of the assemblies and their rows, so a part needed on two boards is
 * counted once (INFERRED).
 *
 * @see https://github.com/sfambach/wp-taxonomy-modeler/blob/main/docs/pakete/fertigung/konzept.md §2
 */
final class Demand
{
    /**
     * @param list<Assembly> $assemblies
     * @param array<int, list<array{id: int, thing: int, count: float, fitting: string}>> $positions revision → positions;
     *        fitting: '' (fitted), 'optional' or 'no'
     * @param array<int, float> $present thing → pieces in the box
     * @return array<int, list<array{position: int, thing: int, needed: float, present: float, missing: float, optional: bool, unused: bool}>>
     *         assembly id → rows, the board itself first (position 0)
     */
    public static function of(array $assemblies, array $positions, int $pieces, array $present): array
    {
        $left = $present;
        $out  = [];

        foreach ($assemblies as $assembly) {
            $rows = [self::row(0, $assembly->revision, (float) $pieces, false, false, $left)];

            foreach ($positions[$assembly->revision] ?? [] as $position) {
                if ($position['fitting'] === 'no' || $position['thing'] <= 0) {
                    continue;
                }

                // Only an option can be left out; a mark on any other position (0.0.2 marked «missing» there) is ignored.
                $optional = $position['fitting'] === 'optional';
                $unused   = $optional && in_array($position['id'], $assembly->unused, true);
                $rows[]   = self::row($position['id'], $position['thing'], $unused ? 0.0 : $position['count'] * $pieces, $optional, $unused, $left);
            }

            $out[$assembly->id] = $rows;
        }

        return $out;
    }

    /**
     * @param array<int, list<array{missing: float}>> $rows from {@see of()}
     */
    public static function missingRows(array $rows): int
    {
        $count = 0;

        foreach ($rows as $list) {
            $count += count(array_filter($list, static fn (array $row): bool => $row['missing'] > 0.0));
        }

        return $count;
    }

    /**
     * @param array<int, float> $left thing → pieces not yet handed out
     * @return array{position: int, thing: int, needed: float, present: float, missing: float, optional: bool, unused: bool}
     */
    private static function row(int $position, int $thing, float $needed, bool $optional, bool $unused, array &$left): array
    {
        $there        = min($needed, $left[$thing] ?? 0.0);
        $left[$thing] = ($left[$thing] ?? 0.0) - $there;

        return ['position' => $position, 'thing' => $thing, 'needed' => $needed, 'present' => $there, 'missing' => max(0.0, $needed - $there), 'optional' => $optional, 'unused' => $unused];
    }
}
