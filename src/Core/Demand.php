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
            // D-1302: only finished boards have used their parts up; a fitted one still holds them in the box.
            $done = min($pieces, $assembly->done);

            foreach ($positions[$assembly->revision] ?? [] as $position) {
                if ($position['fitting'] === 'no' || $position['thing'] <= 0) {
                    continue;
                }

                // His word 2026-11-15: any position can be left out at «Angelegt» (a socket when the IC is soldered directly), not only an option.
                $optional = $position['fitting'] === 'optional';
                $unused   = in_array($position['id'], $assembly->unused, true);
                $boards   = $unused ? 0 : min($pieces, $assembly->fittedOn($position['id']));
                $most     = max($most, $boards);
                $rows[]   = self::row($position['id'], $position['thing'], $position['count'], $unused ? 0.0 : $position['count'] * $pieces, $boards, $unused ? 0 : $done, $optional, $unused, $left);
            }

            // D-1572: the bare board is its own material pointing at the revision. A revision without one has no board row
            // (Prüftor 21.11., step 3: the revision itself is no material any more).
            if ($assembly->boardThing() > 0) {
                // Live-Check 21.11. (88226): with nothing to fit every piece of the board counts as fitted (package assumption §4, 7).
                $most = array_filter($rows, static fn (array $row): bool => ! $row['unused']) === [] ? $pieces : $most;
                array_unshift($rows, self::row(0, $assembly->boardThing(), 1.0, (float) $pieces, $most, $done, false, false, $left));
            }
            $out[$assembly->id] = $rows;
        }

        return $out;
    }

    /**
     * D-1387: a position assigned from stock counts with the material assigned to it, a substitute too; the project's parts
     * list stays as it is (as D-1384).
     *
     * @param array<int, list<array<string, mixed>>> $lists revision → positions, from the parts lists
     * @param array<int, int> $assigned position → material
     * @return array<int, list<array<string, mixed>>>
     */
    public static function withAssigned(array $lists, array $assigned): array
    {
        if ($assigned === []) {
            return $lists;
        }

        foreach ($lists as $revision => $positions) {
            foreach ($positions as $i => $position) {
                if (isset($assigned[$position['id']]) && $assigned[$position['id']] > 0) {
                    $lists[$revision][$i]['thing'] = $assigned[$position['id']];
                }
            }
        }

        return $lists;
    }

    /**
     * Boards with every used position fitted: the smallest count over them (D-1146).
     *
     * @param list<array{position: int, boards: int, unused: bool}> $rows of one assembly
     */
    public static function boardsFitted(array $rows): int
    {
        $counts = array_column(array_filter($rows, static fn (array $row): bool => $row['position'] > 0 && ! $row['unused']), 'boards');

        if ($counts !== []) {
            return min($counts);
        }

        // Live-Check 21.11. (88226): a board with no part to fit is fitted as it is — every piece the board row needs.
        $board = array_values(array_filter($rows, static fn (array $row): bool => $row['position'] === 0));

        return $board === [] ? 0 : (int) round($board[0]['needed'] / max(1.0, $board[0]['count']));
    }

    /**
     * D-1300: after the count falls, the reservations the order no longer needs — whole lines, largest first, as long as what
     * stays reserved plus what lies in the box still covers what is left to fit. A line that is only partly surplus stays.
     *
     * @param array<int, list<array{thing: int, open: float}>> $demand from {@see of()} for the new count
     * @param array<int, float> $box thing → pieces in the box, without the reservations
     * @param list<array{line: int, thing: int, quantity: float}> $lines the order's reservations
     * @return list<int> the lines to release
     */
    public static function surplusReservations(array $demand, array $box, array $lines): array
    {
        $needed = [];

        foreach ($demand as $rows) {
            foreach ($rows as $row) {
                $needed[$row['thing']] = ($needed[$row['thing']] ?? 0.0) + $row['open'];
            }
        }

        $reserved = [];

        foreach ($lines as $line) {
            $reserved[$line['thing']] = ($reserved[$line['thing']] ?? 0.0) + $line['quantity'];
        }

        usort($lines, static fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity']);
        $release = [];

        foreach ($lines as $line) {
            $spare = ($box[$line['thing']] ?? 0.0) + $reserved[$line['thing']] - ($needed[$line['thing']] ?? 0.0);

            if ($line['quantity'] <= $spare + 1e-9) {
                $reserved[$line['thing']] -= $line['quantity'];
                $release[]                 = $line['line'];
            }
        }

        return $release;
    }

    /**
     * His word 16.11. «für Atari haben wir mehrere Aufträge … für welchen sind dann die Teile reserviert in der Kiste»: a
     * project box serves its open orders one after another, the oldest first (D-1301 «Gebinde und Reservierung», D-1030).
     * Each order gets what the orders before it leave; its own reservations are used before the box.
     *
     * @param list<BuildOrder> $orders
     * @param array<int, array<int, list<array{id: int, thing: int, count: float, fitting: string}>>> $lists order → revision → positions
     * @param array<int, array<int, float>> $reserved order → thing → pieces reserved for it
     * @param array<int, array<int, float>> $present box → thing → pieces in it
     * @return array<int, array<int, float>> order → thing → pieces of its box left for it
     */
    public static function boxShares(array $orders, array $lists, array $reserved, array $present): array
    {
        return self::boxSplit($orders, $lists, $reserved, $present)[0];
    }

    /**
     * Stefan 22.11. (Atari, 3 strips for 3 orders): which older orders of the same box took what of it — the reason a line
     * lacks what lies in the box. Same split as {@see boxShares()}.
     *
     * @param list<BuildOrder> $orders
     * @param array<int, array<int, list<array{id: int, thing: int, count: float, fitting: string}>>> $lists
     * @param array<int, array<int, float>> $reserved
     * @param array<int, array<int, float>> $present
     * @return array<int, array<int, array<int, float>>> order → thing → older order → pieces it took from the box
     */
    public static function boxTakers(array $orders, array $lists, array $reserved, array $present): array
    {
        [, $taken] = self::boxSplit($orders, $lists, $reserved, $present);
        $out       = [];

        foreach ($orders as $order) {
            foreach ($orders as $older) {
                if ($older->id >= $order->id || $older->box === null || $older->box !== $order->box) {
                    continue;
                }

                foreach ($taken[$older->id] ?? [] as $thing => $pieces) {
                    $out[$order->id][$thing][$older->id] = $pieces;
                }
            }
        }

        return $out;
    }

    /** @return array{0: array<int, array<int, float>>, 1: array<int, array<int, float>>} shares, and order → thing → pieces it took from the box */
    private static function boxSplit(array $orders, array $lists, array $reserved, array $present): array
    {
        usort($orders, static fn (BuildOrder $a, BuildOrder $b): int => $a->id <=> $b->id);
        $left  = $present;
        $out   = [];
        $taken = [];

        foreach ($orders as $order) {
            if ($order->box === null) {
                $out[$order->id] = [];
                continue;
            }

            $box             = $left[$order->box] ?? [];
            $own             = $reserved[$order->id] ?? [];
            $out[$order->id] = $box;

            if ($order->isClosed()) {
                continue;
            }

            $there = $box;

            foreach ($own as $thing => $pieces) {
                $there[$thing] = ($there[$thing] ?? 0.0) + $pieces;
            }

            $used = [];

            foreach (self::of($order->assemblies, $lists[$order->id] ?? [], $order->count, $there) as $rows) {
                foreach ($rows as $row) {
                    $used[$row['thing']] = ($used[$row['thing']] ?? 0.0) + $row['present'];
                }
            }

            foreach ($used as $thing => $pieces) {
                $fromBox = max(0.0, $pieces - ($own[$thing] ?? 0.0));

                if ($fromBox > 0.0) {
                    $left[$order->box][$thing] = max(0.0, ($box[$thing] ?? 0.0) - $fromBox);
                    $taken[$order->id][$thing] = min($fromBox, $box[$thing] ?? 0.0);
                }
            }
        }

        return [$out, $taken];
    }

    /**
     * What a finish would use up that its box does not hold: thing → pieces short (his word 16.11. «wenn halt nichts mehr da
     * ist»). The parts of a finished board are fitted, so they must lie in the box.
     *
     * @param array<int, float> $use thing → pieces, from {@see finished()}
     * @param array<int, float> $box thing → pieces in the box
     * @return array<int, float>
     */
    public static function shortfall(array $use, array $box): array
    {
        $short = [];

        foreach ($use as $thing => $pieces) {
            $lack = $pieces - ($box[$thing] ?? 0.0);

            if ($lack > 1e-9) {
                $short[$thing] = $lack;
            }
        }

        return $short;
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
     * D-1566 (Stefan 21.11. 15:08 «der findet die im eigenen Gebinde nicht»): a position whose part already has the position's
     * pins is a strip cut to length — it counts in pieces, not in pins (D-1398 stays for a long strip).
     *
     * @param array<int, int> $piece    position → pins per piece
     * @param array<int, int> $partOf   position → its part
     * @param array<int, int> $partPins part → its own pins, where set
     * @return array<int, int> position → pins per piece, without the cut strips
     */
    public static function withoutCutStrips(array $piece, array $partOf, array $partPins): array
    {
        return array_filter($piece, static fn (int $pins, int $position): bool => ($partPins[$partOf[$position] ?? 0] ?? 0) !== $pins, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Adds the pins lost at cutting to each position's count (D-1178): one cut per piece, a piece per reference designator
     * («J1, J2» are two pieces), at least one (INFERRED).
     *
     * @param list<array{id: int, thing: int, count: float, fitting: string, ref: string}> $positions
     * @param array<int, int> $loss thing → pins lost per cut
     * @return list<array{id: int, thing: int, count: float, fitting: string, ref: string}>
     */
    public static function withCutLoss(array $positions, array $loss): array
    {
        foreach ($positions as $i => $position) {
            $per = $loss[$position['thing']] ?? 0;

            if ($per > 0) {
                $pieces                  = max(1, count(array_filter(preg_split('/[\s,;]+/', $position['ref']) ?: [], static fn (string $r): bool => $r !== '')));
                $positions[$i]['count'] = $position['count'] + $pieces * $per;
            }
        }

        return $positions;
    }

    /**
     * Fitted parts as entered → the boards they make up per position, which is what is stored (D-1146). The page asks for
     * parts because «3 / 10» next to a demand of 30 read as parts (his question 2026-11-04 «Soll ich dann da 3 eintragen?»).
     * Started boards do not count: 10 parts at 3 per board are 3 boards.
     *
     * @param array<int, float> $pieces position → fitted parts
     * @param list<array{id: int, count: float}> $positions of the assembly's revision
     * @return array<int, int> position → boards
     */
    public static function boardsFromPieces(array $pieces, array $positions): array
    {
        $per    = array_column($positions, 'count', 'id');
        $boards = [];

        foreach ($pieces as $position => $count) {
            $each               = (float) ($per[$position] ?? 0.0);
            $boards[$position] = $each > 0.0 ? (int) floor($count / $each + 1e-9) : 0;
        }

        return $boards;
    }

    /**
     * D-1302 «erst bei fertig»: what finishing `$made` more boards takes out of the box and the order's reservations — each
     * used position × its count, and the bare board ({@see Assembly::boardThing()}, D-1572); negative gives back. A left-out position
     * and one not fitted at all («no») take nothing.
     *
     * @param list<array{id: int, thing: int, count: float, fitting: string}> $positions of the assembly's revision
     * @param list<int> $unused positions left out of this assembly
     * @return array<int, float> thing → pieces, without zeros
     */
    public static function finished(Assembly $assembly, int $made, array $positions, array $unused): array
    {
        if ($made === 0) {
            return [];
        }

        $out = [];

        foreach ($positions as $position) {
            if ($position['thing'] <= 0 || $position['fitting'] === 'no' || in_array($position['id'], $unused, true)) {
                continue;
            }

            $out[$position['thing']] = ($out[$position['thing']] ?? 0.0) + $made * $position['count'];
        }

        if ($assembly->boardThing() > 0) {
            $out[$assembly->boardThing()] = ($out[$assembly->boardThing()] ?? 0.0) + $made;
        }

        return array_filter($out, static fn (float $n): bool => $n !== 0.0);
    }

    private static function row(int $position, int $thing, float $count, float $needed, int $boards, int $done, bool $optional, bool $unused, array &$left): array
    {
        $open         = max(0.0, $needed - $done * $count);
        $there        = min($open, $left[$thing] ?? 0.0);
        $left[$thing] = ($left[$thing] ?? 0.0) - $there;

        return ['position' => $position, 'thing' => $thing, 'count' => $count, 'needed' => $needed, 'boards' => $boards, 'open' => $open, 'present' => $there, 'missing' => max(0.0, $open - $there), 'optional' => $optional, 'unused' => $unused];
    }
}
