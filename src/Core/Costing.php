<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * The price of one completed board (D-1425): parts at the price of the lots actually consumed, the share of the set-up, the
 * work — frozen as a breakdown that the stock keeps at the board's lot and the sale reads.
 *
 * ```mermaid
 * flowchart LR
 *   P["Teile: Menge × Stückpreis des verbrauchten Postens"] --> T["Summe je Platine"]
 *   S["Bereitstellung: Stunden × Lohn ÷ Platinen des Auftrags"] --> T
 *   W["Arbeit: Minuten ÷ 60 × Lohn"] --> T
 *   X["Ausschuss (nicht eingerechnet, INF-132)"] -.-> T
 * ```
 *
 * @see /mnt/project-files/taxmod-fertigung/kalkulation-konzept.md
 */
final class Costing
{
    public const SOURCES = ['lot', 'last_purchase', 'none'];

    /**
     * @param array<int, float> $consumed thing → pieces used up for `$boards` boards
     * @param array<int, list<array{lot: int, quantity: float, unit_price: ?float, source: string}>> $prices thing → the lots the
     *        stock takes them from, with their price
     * @param array<int, float> $scrap thing → pieces scrapped in this order so far; shown, not counted (INF-132)
     * @param int $share how many boards the set-up is spread over: the order's count times its boards (D-1425: «so that partial
     *        completions add up to it once»)
     * @return array{order: int, date: string, parts: list<array{thing: int, quantity: float, unit_price: ?float, source: string}>,
     *         parts_total: float, complete: bool, setup: array{hours: float, wage: float, boards: int, amount: float},
     *         work: array{minutes: float, wage: float, amount: float}, scrap: list<array{thing: int, quantity: float}>, total: float}
     */
    public static function perBoard(int $order, string $date, int $boards, array $consumed, array $prices, float $minutes, float $wage, float $setupHours, int $share, array $scrap = []): array
    {
        if ($boards < 1 || $share < 1) {
            throw ManufacturingError::notReadyToFinish($boards, 0);
        }

        $parts    = [];
        $total    = 0.0;
        $complete = true;

        foreach ($consumed as $thing => $pieces) {
            $part = self::part((int) $thing, $pieces / $boards, $pieces, $prices[$thing] ?? []);

            if ($part['unit_price'] === null) {
                $complete = false;
            } else {
                $total += $part['quantity'] * $part['unit_price'];
            }

            $parts[] = $part;
        }

        $setup = ['hours' => $setupHours, 'wage' => $wage, 'boards' => $share, 'amount' => $setupHours * $wage / $share];
        $work  = ['minutes' => $minutes, 'wage' => $wage, 'amount' => $minutes / 60 * $wage];

        return [
            'order'       => $order,
            'date'        => $date,
            'parts'       => $parts,
            'parts_total' => round($total, 4),
            'complete'    => $complete,
            'setup'       => ['amount' => round($setup['amount'], 4)] + $setup,
            'work'        => ['amount' => round($work['amount'], 4)] + $work,
            'scrap'       => array_values(array_map(static fn (int $thing, float $n): array => ['thing' => $thing, 'quantity' => $n], array_keys($scrap), $scrap)),
            'total'       => round($total + $setup['amount'] + $work['amount'], 4),
        ];
    }

    /**
     * One position: the weighted price of the lots it is taken from. A lot without any price leaves the whole position
     * «ohne Preis» — a part of it priced would read as the full price (D-1425: «never 0»).
     *
     * @param list<array{lot: int, quantity: float, unit_price: ?float, source: string}> $lots
     * @return array{thing: int, quantity: float, unit_price: ?float, source: string}
     */
    private static function part(int $thing, float $perBoard, float $pieces, array $lots): array
    {
        $covered = array_sum(array_map(static fn (array $lot): float => (float) $lot['quantity'], $lots));
        $none    = $lots === [] || $covered + 1e-9 < $pieces;
        $amount  = 0.0;
        $source  = 'lot';

        foreach ($lots as $lot) {
            if ($lot['unit_price'] === null || $lot['source'] === 'none') {
                $none = true;
                break;
            }

            $amount += (float) $lot['quantity'] * $lot['unit_price'];

            if ($lot['source'] === 'last_purchase') {
                $source = 'last_purchase';
            }
        }

        return $none
            ? ['thing' => $thing, 'quantity' => $perBoard, 'unit_price' => null, 'source' => 'none']
            : ['thing' => $thing, 'quantity' => $perBoard, 'unit_price' => round($amount / $covered, 6), 'source' => $source];
    }
}
