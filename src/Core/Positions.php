<?php declare(strict_types=1);

namespace TaxmodManufacturing\Core;

/**
 * The status of each board of an order, derived from what the stock holds of it — never stored (D-1433, D-1342: place and
 * condition say it). A board not yet completed has no lot and is «In Fertigung»; a completed one is its lot's condition.
 * From «In Fertigung» on the order's own status is the earliest open status of its boards.
 *
 * ```mermaid
 * flowchart LR
 *   B["In Fertigung (Stück − abgeschlossen)"] -->|"abschließen"| T["Test (ungetestet)"]
 *   T -->|"Nacharbeit"| R["Nacharbeit (defekt)"] -->|"zurück in Test"| T
 *   T -->|"Bestanden"| D["Fertiggestellt (funktioniert)"]
 *   R -->|"Verschrotten"| S["Verschrottet"]
 *   T -->|"Verschrotten"| S
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1433
 */
final class Positions
{
    public const BUILDING = 'building';
    public const TEST     = 'test';
    public const REWORK   = 'rework';
    public const DONE     = 'done';
    public const SCRAPPED = 'scrapped';

    /** The statuses in their order; the earliest open one is the order's. */
    public const ALL  = [self::BUILDING, self::TEST, self::REWORK, self::DONE, self::SCRAPPED];
    public const OPEN = [self::BUILDING, self::TEST, self::REWORK];

    /** A lot's status from its condition as the stock names it: untested|passed|failed|scrapped. */
    public static function ofLot(string $state): string
    {
        return match ($state) {
            'passed'   => self::DONE,
            'failed'   => self::REWORK,
            'scrapped' => self::SCRAPPED,
            default    => self::TEST,
        };
    }

    /**
     * How many boards stand in each status.
     *
     * @param array<int, array{state: string}> $lots the order's completed boards, one lot each (D-1342)
     * @return array<string, int> status → boards, every status present
     */
    public static function counts(BuildOrder $order, array $lots): array
    {
        $counts = array_fill_keys(self::ALL, 0);

        foreach ($order->assemblies as $assembly) {
            $counts[self::BUILDING] += max(0, $order->count - $assembly->done);
        }

        foreach ($lots as $lot) {
            ++$counts[self::ofLot((string) $lot['state'])];
        }

        // Boards completed before the stock kept one lot per board: what the order already called done stays done.
        $unknown = $order->doneBoards() - count($lots);

        if ($unknown > 0) {
            $counts[$order->step === 'order_done' ? self::DONE : self::TEST] += $unknown;
        }

        return $counts;
    }

    /**
     * The order's step: as stored up to «In Fertigung» and for «Abgebrochen»; from there the earliest open status of its
     * boards (D-1433 A) — «Test» while one is in test or rework, «Fertiggestellt» when none is open.
     *
     * @param array<int, array{state: string}> $lots
     */
    public static function orderStep(BuildOrder $order, array $lots): string
    {
        if (! in_array($order->step, ['order_building', 'order_test', 'order_done'], true) || $order->assemblies === []) {
            return $order->step;
        }

        $counts = self::counts($order, $lots);

        return match (true) {
            $counts[self::BUILDING] > 0                       => 'order_building',
            $counts[self::TEST] + $counts[self::REWORK] > 0   => 'order_test',
            default                                           => 'order_done',
        };
    }

    /**
     * The order as its boards say it is.
     *
     * @param array<int, array{state: string}> $lots
     */
    public static function derive(BuildOrder $order, array $lots): BuildOrder
    {
        $step = self::orderStep($order, $lots);

        return $step === $order->step ? $order : new BuildOrder($order->id, $order->label, $order->project, $order->count, $step, $order->box, $order->started,
            $order->finished, $order->assemblies, $order->shortageReason, $order->due, $order->kit, $order->note);
    }
}
