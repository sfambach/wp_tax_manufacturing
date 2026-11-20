<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * The staging of a build order, one line per material: what is needed, what lies in the box, what is reserved elsewhere and
 * what is missing, and beneath it where it can come from (specification §3, D-1301, D-1387).
 *
 * ```mermaid
 * flowchart LR
 *   D["Demand: still to fit"] --> L["line per material"]
 *   B["box share"] --> L
 *   R["reservations outside the box"] --> L
 *   L --> S["sources: reserved rows, free places, «nicht zugewiesen»"]
 * ```
 *
 * A reservation whose lot already lies in the box counts as in the box, not a second time as reserved (his report 16.11.).
 *
 * @see /mnt/project-files/taxmod-fertigung/spezifikation-fertigungsprozess.md §3
 */
final class Staging
{
    /**
     * What counts as there for the demand: the box and what is reserved outside it.
     *
     * @param array<int, float> $box thing → pieces in the box (the order's share)
     * @param list<array{line: int, thing: int, quantity: float, places: array<int, float>, position: int}> $reservations
     * @return array<int, float> thing → pieces
     */
    public static function available(array $box, array $reservations, ?int $boxId): array
    {
        $there = $box;

        foreach (self::outside($reservations, $boxId) as $thing => $pieces) {
            $there[$thing] = ($there[$thing] ?? 0.0) + $pieces;
        }

        return $there;
    }

    /**
     * @param array<int, list<array{thing: int, open: float, missing: float}>> $demand from {@see Demand::of()} with {@see available()}
     * @param array<int, float> $box thing → pieces in the box
     * @param list<array{line: int, thing: int, quantity: float, places: array<int, float>, position: int}> $reservations
     * @param array<int, array<int, float>> $places thing → place → pieces, the box left out
     * @return list<array{thing: int, needed: float, box: float, reserved: float, missing: float, sources: list<array{kind: string, thing: int, place: ?int, there: float, quantity: float, line: int, position: int}>}>
     */
    public static function lines(array $demand, array $box, array $reservations, array $places, ?int $boxId): array
    {
        $needed  = [];
        $missing = [];

        foreach ($demand as $rows) {
            foreach ($rows as $row) {
                $needed[$row['thing']]  = ($needed[$row['thing']] ?? 0.0) + $row['open'];
                $missing[$row['thing']] = ($missing[$row['thing']] ?? 0.0) + $row['missing'];
            }
        }

        $outside = self::outside($reservations, $boxId);
        $held    = [];

        foreach ($reservations as $reservation) {
            foreach ($reservation['places'] ?: [0 => $reservation['quantity']] as $place => $pieces) {
                $held[$reservation['thing']][] = [
                    'kind'     => $boxId !== null && $place === $boxId ? 'reserved_box' : 'reserved',
                    'thing'    => $reservation['thing'],
                    'place'    => $place > 0 ? (int) $place : null,
                    'there'    => (float) $pieces,
                    'quantity' => min((float) $pieces, $reservation['quantity']),
                    'line'     => $reservation['line'],
                    'position' => $reservation['position'],
                ];
            }
        }

        $out = [];

        foreach (array_unique([...array_keys($needed), ...array_keys($held)]) as $thing) {
            if (($needed[$thing] ?? 0.0) <= 0.0 && ! isset($held[$thing])) {
                continue;
            }

            $sources = $held[$thing] ?? [];
            $left    = $missing[$thing] ?? 0.0;
            $at      = array_filter($places[$thing] ?? [], static fn (float $q): bool => $q > 0.0);
            arsort($at);

            foreach ($at as $place => $there) {
                if ($left <= 1e-9) {
                    break;
                }

                $take      = min($left, $there);
                $left     -= $take;
                $sources[] = ['kind' => 'free', 'thing' => $thing, 'place' => (int) $place, 'there' => $there, 'quantity' => $take, 'line' => 0, 'position' => 0];
            }

            if ($left > 1e-9) {
                $sources[] = ['kind' => 'none', 'thing' => $thing, 'place' => null, 'there' => 0.0, 'quantity' => $left, 'line' => 0, 'position' => 0];
            }

            $out[] = [
                'thing'    => $thing,
                'needed'   => $needed[$thing] ?? 0.0,
                'box'      => $box[$thing] ?? 0.0,
                'reserved' => $outside[$thing] ?? 0.0,
                'missing'  => $missing[$thing] ?? 0.0,
                'sources'  => $sources,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{thing: int, quantity: float, places: array<int, float>}> $reservations
     * @return array<int, float> thing → pieces reserved outside the box
     */
    private static function outside(array $reservations, ?int $boxId): array
    {
        $out = [];

        foreach ($reservations as $reservation) {
            $inBox = $boxId !== null ? min($reservation['quantity'], (float) ($reservation['places'][$boxId] ?? 0.0)) : 0.0;
            $rest  = $reservation['quantity'] - $inBox;

            if ($rest > 0.0) {
                $out[$reservation['thing']] = ($out[$reservation['thing']] ?? 0.0) + $rest;
            }
        }

        return $out;
    }

    /**
     * D-1535 (P1): the pieces still missing of each strip counted in pins, by length — material → pins per piece →
     * pieces, from the positions' missing pins and their pins per piece. A position without pins per piece is no cut.
     *
     * @param array<int, list<array{position: int, thing: int, missing: float}>> $demand
     * @param array<int, int> $piece position → pins per piece
     * @return array<int, array<int, int>>
     */
    public static function pieces(array $demand, array $piece): array
    {
        $out = [];

        foreach ($demand as $rows) {
            foreach ($rows as $row) {
                $pins = $piece[$row['position']] ?? 0;

                if ($pins > 0 && $row['missing'] > 0.000001) {
                    $out[$row['thing']][$pins] = ($out[$row['thing']][$pins] ?? 0) + (int) ceil($row['missing'] / $pins - 1e-9);
                }
            }
        }

        foreach ($out as &$lengths) {
            krsort($lengths);
        }

        return $out;
    }
}
