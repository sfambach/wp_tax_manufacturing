<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\Demand;
use TaxmodManufacturing\Core\Staging;

/** Specification §3: one line per material with need, box, reserved and missing; D-1301, D-1387. */
final class StagingTest extends TestCase
{
    private const BOX      = 85697;
    private const PLATINEN = 500;
    private const BOARD    = 85710;

    /** His ISA-Riser 16.11.: 8 boards, 5 in the box, 3 reserved at «Platinen» — nothing is missing, and the line says why. */
    public function testBoxAndReservationCoverTheNeed(): void
    {
        $assembly     = new Assembly(1, self::BOARD, [], []);
        $reservations = [['line' => 11, 'thing' => self::BOARD, 'quantity' => 3.0, 'places' => [self::PLATINEN => 3.0], 'position' => 0]];
        $box          = [self::BOARD => 5.0];
        $demand       = Demand::of([$assembly], [], 8, Staging::available($box, $reservations, self::BOX));
        $lines        = Staging::lines($demand, $box, $reservations, [self::BOARD => [self::PLATINEN => 15.0]], self::BOX);

        self::assertCount(1, $lines);
        self::assertSame([8.0, 5.0, 3.0, 0.0], [$lines[0]['needed'], $lines[0]['box'], $lines[0]['reserved'], $lines[0]['missing']]);
        self::assertSame(['reserved'], array_column($lines[0]['sources'], 'kind'));
        self::assertSame(11, $lines[0]['sources'][0]['line']);
    }

    /** Without the reservation the three missing come from the largest stock: the proposal is 8 − 5 = 3. */
    public function testMissingIsNeedLessBox(): void
    {
        $assembly = new Assembly(1, self::BOARD, [], []);
        $box      = [self::BOARD => 5.0];
        $demand   = Demand::of([$assembly], [], 8, Staging::available($box, [], self::BOX));
        $lines    = Staging::lines($demand, $box, [], [self::BOARD => [self::PLATINEN => 15.0]], self::BOX);

        self::assertSame(3.0, $lines[0]['missing']);
        self::assertSame([['free', self::PLATINEN, 15.0, 3.0]], array_map(static fn (array $s): array => [$s['kind'], $s['place'], $s['there'], $s['quantity']], $lines[0]['sources']));
    }

    /** A reservation whose lot lies in the box is counted once, as in the box. */
    public function testReservationInTheBoxIsNotCountedTwice(): void
    {
        $reservations = [['line' => 12, 'thing' => self::BOARD, 'quantity' => 3.0, 'places' => [self::BOX => 3.0], 'position' => 0]];

        self::assertSame([self::BOARD => 5.0], Staging::available([self::BOARD => 5.0], $reservations, self::BOX));

        $demand = Demand::of([new Assembly(1, self::BOARD, [], [])], [], 8, Staging::available([self::BOARD => 5.0], $reservations, self::BOX));
        $lines  = Staging::lines($demand, [self::BOARD => 5.0], $reservations, [], self::BOX);

        self::assertSame([3.0, 0.0], [$lines[0]['missing'], $lines[0]['reserved']]);
        self::assertSame(['reserved_box', 'none'], array_column($lines[0]['sources'], 'kind'));
    }

    /** What is found nowhere reads «nicht zugewiesen» (D-1387). */
    public function testNothingAnywhereIsUnassigned(): void
    {
        $demand = Demand::of([new Assembly(1, self::BOARD, [], [])], [], 2, []);
        $lines  = Staging::lines($demand, [], [], [], self::BOX);

        self::assertSame([['none', null, 2.0]], array_map(static fn (array $s): array => [$s['kind'], $s['place'], $s['quantity']], $lines[0]['sources']));
    }

    public function testTheMissingPiecesOfAStripAreCountedByLengthLongestFirst(): void
    {
        // Pin-Aufbereitung P1: P1/P2 1×5 (10 pins missing), J1 1×6 (6), J2–J4 1×2 (6) — all from one 1×40 material 51307.
        $demand = [7 => [
            ['position' => 1, 'thing' => 51307, 'missing' => 10.0],
            ['position' => 2, 'thing' => 51307, 'missing' => 6.0],
            ['position' => 3, 'thing' => 51307, 'missing' => 6.0],
            ['position' => 4, 'thing' => 900, 'missing' => 3.0],
        ]];

        self::assertSame([51307 => [6 => 1, 5 => 2, 2 => 3]], Staging::pieces($demand, [1 => 5, 2 => 6, 3 => 2]));
    }
}
