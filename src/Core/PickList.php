<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * What to fetch into an order's box: for every part the box lacks, the places it lies at and how much to take from each (D-1155).
 *
 * @see docs/pakete/fertigung/konzept.md
 */
final class PickList
{
    /**
     * The largest stock first, so a part comes from as few places as possible. A part found nowhere keeps one line
     * without a place, so the list still says it is missing.
     *
     * @param array<int, list<array{thing: int, missing: float}>> $demand from {@see Demand::of()}
     * @param array<int, array<int, float>> $places thing → place → pieces there, the box itself left out
     * @return list<array{thing: int, place: ?int, there: float, take: float, missing: float}>
     */
    public static function of(array $demand, array $places): array
    {
        $missing = [];

        foreach ($demand as $rows) {
            foreach ($rows as $row) {
                if ($row['missing'] > 0.0) {
                    $missing[$row['thing']] = ($missing[$row['thing']] ?? 0.0) + $row['missing'];
                }
            }
        }

        $lines = [];

        foreach ($missing as $thing => $need) {
            $at = array_filter($places[$thing] ?? [], static fn (float $q): bool => $q > 0.0);
            arsort($at);
            $left = $need;

            foreach ($at as $place => $there) {
                if ($left <= 0.0) {
                    break;
                }

                $take    = min($left, $there);
                $left   -= $take;
                $lines[] = ['thing' => $thing, 'place' => (int) $place, 'there' => $there, 'take' => $take, 'missing' => $need];
            }

            if ($left > 0.0) {
                $lines[] = ['thing' => $thing, 'place' => null, 'there' => 0.0, 'take' => 0.0, 'missing' => $left];
            }
        }

        return $lines;
    }
}
