<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * What a build order needs, board by board, against what is fitted already and what lies in its box (D-1145, D-1146).
 *
 * ```mermaid
 * flowchart LR
 *   A["assembly: revision"] --> B["board itself × pieces"]
 *   A --> P["positions: count × pieces<br/>without «nein» and unused options"]
 *   F["fitted on n boards"] --> R["still to fit = needed − n × count"]
 *   P --> R
 *   B --> R
 *   X["box and the places in it"] --> M["missing = still to fit − there"]
 *   R --> M
 * ```
 *
 * What lies in the box is handed out in the order of the assemblies and their rows, so a part needed on two boards is
 * counted once (INFERRED). The board itself counts as used once any of its positions is fitted: its fitted count is the
 * largest of theirs (INFERRED, D-1146).
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
     * @return array<int, list<array{position: int, thing: int, count: float, needed: float, boards: int, open: float, present: float, missing: float, optional: bool, unused: bool}>>
     *         assembly id → rows, the board itself first (position 0); `boards`: on how many boards it is fitted, `open`: still to fit
     */
    public static function of(array $assemblies, array $positions, int $pieces, array $present): array
    {
        $left = $present;
        $out  = [];

        foreach ($assemblies as $assembly) {
            $rows = [];
            $most = 0;

            foreach ($positions[$assembly->revision] ?? [] as $position) {
                if ($position['fitting'] === 'no' || $position['thing'] <= 0) {
                    continue;
                }

                // Only an option can be left out; a mark on any other position (0.0.2 marked «missing» there) is ignored.
                $optional = $position['fitting'] === 'optional';
                $unused   = $optional && in_array($position['id'], $assembly->unused, true);
                $boards   = $unused ? 0 : min($pieces, $assembly->fittedOn($position['id']));
                $most     = max($most, $boards);
                $rows[]   = self::row($position['id'], $position['thing'], $position['count'], $unused ? 0.0 : $position['count'] * $pieces, $boards, $optional, $unused, $left);
            }

            array_unshift($rows, self::row(0, $assembly->revision, 1.0, (float) $pieces, $most, false, false, $left));
            $out[$assembly->id] = $rows;
        }

        return $out;
    }

    /**
     * Boards with every used position fitted: the smallest count over them (D-1146).
     *
     * @param list<array{position: int, boards: int, unused: bool}> $rows of one assembly
     */
    public static function boardsFitted(array $rows): int
    {
        $counts = array_column(array_filter($rows, static fn (array $row): bool => $row['position'] > 0 && ! $row['unused']), 'boards');

        return $counts === [] ? 0 : min($counts);
    }

    /** @param array<int, list<array{missing: float}>> $rows from {@see of()} */
    public static function missingRows(array $rows): int
    {
        $count = 0;

        foreach ($rows as $list) {
            $count += count(array_filter($list, static fn (array $row): bool => $row['missing'] > 0.0));
        }

        return $count;
    }

    /**
     * What a change of fitted counts takes out of the box (positive) or puts back (negative): the difference × the count of
     * each position, and for the board itself the difference of its largest count.
     *
     * @param array<int, int> $new position → boards, for every position shown
     * @param list<array{id: int, thing: int, count: float, fitting: string}> $positions of the assembly's revision
     * @return array<int, float> thing → pieces, without zeros
     */
    public static function consumption(Assembly $assembly, array $new, array $positions): array
    {
        $out    = [];
        $oldMax = 0;
        $newMax = 0;

        foreach ($positions as $position) {
            if ($position['thing'] <= 0 || ! array_key_exists($position['id'], $new)) {
                continue;
            }

            $old    = $assembly->fittedOn($position['id']);
            $oldMax = max($oldMax, $old);
            $newMax = max($newMax, $new[$position['id']]);
            $out[$position['thing']] = ($out[$position['thing']] ?? 0.0) + ($new[$position['id']] - $old) * $position['count'];
        }

        $out[$assembly->revision] = ($out[$assembly->revision] ?? 0.0) + ($newMax - $oldMax);

        return array_filter($out, static fn (float $n): bool => $n !== 0.0);
    }

    /**
     * @param array<int, float> $left thing → pieces not yet handed out
     * @return array{position: int, thing: int, count: float, needed: float, boards: int, open: float, present: float, missing: float, optional: bool, unused: bool}
     */
    private static function row(int $position, int $thing, float $count, float $needed, int $boards, bool $optional, bool $unused, array &$left): array
    {
        $open         = max(0.0, $needed - $boards * $count);
        $there        = min($open, $left[$thing] ?? 0.0);
        $left[$thing] = ($left[$thing] ?? 0.0) - $there;

        return ['position' => $position, 'thing' => $thing, 'count' => $count, 'needed' => $needed, 'boards' => $boards, 'open' => $open, 'present' => $there, 'missing' => max(0.0, $open - $there), 'optional' => $optional, 'unused' => $unused];
    }
}
