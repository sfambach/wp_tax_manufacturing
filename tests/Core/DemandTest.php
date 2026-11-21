<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Demand;

final class DemandTest extends TestCase
{
    private const POSITIONS = [305 => [
        ['id' => 700, 'thing' => 9001, 'count' => 3.0, 'fitting' => ''],
        ['id' => 701, 'thing' => 9002, 'count' => 1.0, 'fitting' => ''],
        ['id' => 702, 'thing' => 9003, 'count' => 1.0, 'fitting' => 'no'],
        ['id' => 703, 'thing' => 9004, 'count' => 1.0, 'fitting' => 'optional'],
    ]];

    /**
     * His s7vrm: 10 pieces, 2 fitted with 700, the coil (701) on none yet; the option 703 left out. D-1302 (replaces the rule
     * of D-1146 that fitting used parts up): a fitted board still holds its parts in the box, so only finished boards lower
     * what is still needed.
     */
    public function testOnlyFinishedBoardsReduceWhatIsStillNeeded(): void
    {
        $fitted = new Assembly(600, 305, [700 => ['part' => 1, 'boards' => 2]], [703]);
        $rows   = Demand::of([$fitted], self::POSITIONS, 10, [305 => 8.0, 9001 => 20.0, 9002 => 3.0])[600];

        self::assertSame([0, 700, 701, 703], array_column($rows, 'position'));
        self::assertSame([10.0, 30.0, 10.0, 0.0], array_column($rows, 'needed'));
        self::assertSame([2, 2, 0, 0], array_column($rows, 'boards'));
        self::assertSame([10.0, 30.0, 10.0, 0.0], array_column($rows, 'open'));
        self::assertSame([2.0, 10.0, 7.0, 0.0], array_column($rows, 'missing'));
        self::assertSame(0, Demand::boardsFitted($rows));
        self::assertSame(3, Demand::missingRows([600 => $rows]));

        $done = new Assembly(600, 305, [700 => ['part' => 1, 'boards' => 2], 701 => ['part' => 2, 'boards' => 2]], [703], 2, 2);
        $rows = Demand::of([$done], self::POSITIONS, 10, [305 => 8.0, 9001 => 24.0, 9002 => 8.0])[600];

        self::assertSame([8.0, 24.0, 8.0, 0.0], array_column($rows, 'open'));
        self::assertSame(0, Demand::missingRows([600 => $rows]));
    }

    public function testBoardsFittedIsTheSmallestCount(): void
    {
        $assembly = new Assembly(600, 305, [700 => ['part' => 1, 'boards' => 3], 701 => ['part' => 2, 'boards' => 2]], [703]);

        self::assertSame(2, Demand::boardsFitted(Demand::of([$assembly], self::POSITIONS, 10, [])[600]));
    }

    /** D-1302 «erst bei fertig»: finishing boards uses up each used position × its count and the board; fewer gives back. */
    public function testFinishingUsesUpThePartsOfTheFinishedBoards(): void
    {
        $assembly = new Assembly(600, 305, [700 => ['part' => 1, 'boards' => 2]], []);

        self::assertSame([9001 => 6.0, 9002 => 2.0, 9004 => 2.0, 305 => 2.0], Demand::finished($assembly, 2, self::POSITIONS[305], []));
        self::assertSame([9001 => -3.0, 9002 => -1.0, 305 => -1.0], Demand::finished($assembly, -1, self::POSITIONS[305], [703]));
        self::assertSame([], Demand::finished($assembly, 0, self::POSITIONS[305], []));
    }

    /** D-1388 (replaces the board-record rule of D-1322): the bare board is needed and consumed as the revision record. */
    public function testBareBoardIsTheRevisionRecord(): void
    {
        $assembly = new Assembly(600, 305, [], []);
        $demand   = Demand::of([$assembly], self::POSITIONS, 2, [305 => 2.0, 200 => 2.0]);

        self::assertSame(305, $demand[600][0]['thing']);
        self::assertSame(0.0, $demand[600][0]['missing']);
        self::assertSame([9001 => 3.0, 9002 => 1.0, 305 => 1.0], Demand::finished($assembly, 1, self::POSITIONS[305], [703]));
    }

    public function testBoxIsHandedOutOnce(): void
    {
        $positions = [1 => [['id' => 10, 'thing' => 50, 'count' => 1.0, 'fitting' => '']], 2 => [['id' => 20, 'thing' => 50, 'count' => 1.0, 'fitting' => '']]];
        $demand    = Demand::of([new Assembly(100, 1, [], []), new Assembly(200, 2, [], [])], $positions, 1, [50 => 1.0]);

        self::assertSame(0.0, $demand[100][1]['missing']);
        self::assertSame(1.0, $demand[200][1]['missing']);
    }

    public function testPartsEnteredBecomeWholeBoards(): void
    {
        $positions = [['id' => 1, 'count' => 3.0], ['id' => 2, 'count' => 1.0], ['id' => 3, 'count' => 0.0]];

        self::assertSame([1 => 10, 2 => 4, 3 => 0], Demand::boardsFromPieces([1 => 30.0, 2 => 4.0, 3 => 5.0], $positions));
        self::assertSame([1 => 3], Demand::boardsFromPieces([1 => 10.0], $positions));
    }

    public function testCutLossAddsOnePerPiece(): void
    {
        $positions = [
            ['id' => 1, 'thing' => 7, 'count' => 40.0, 'fitting' => '', 'ref' => 'J1, J2'],
            ['id' => 2, 'thing' => 7, 'count' => 8.0, 'fitting' => '', 'ref' => ''],
            ['id' => 3, 'thing' => 9, 'count' => 3.0, 'fitting' => '', 'ref' => 'R1 R2'],
        ];
        $result = Demand::withCutLoss($positions, [7 => 1]);

        self::assertSame([42.0, 9.0, 3.0], array_column($result, 'count'));
    }

    /**
     * His word 2026-11-15 («den Sockel weglassen»): a mandatory position left out at «Angelegt» drops out of demand, pick list
     * and «missing» like an option; it no longer holds the fitted count down. Before, only an option could be left out (D-954).
     */
    public function testALeftOutMandatoryPositionIsNeitherNeededNorMissing(): void
    {
        $assembly = new Assembly(600, 305, [700 => ['part' => 1, 'boards' => 2]], [701, 703]);
        $rows     = Demand::of([$assembly], self::POSITIONS, 10, [305 => 10.0, 9001 => 30.0])[600];
        $socket   = $rows[2];

        self::assertSame(701, $socket['position']);
        self::assertTrue($socket['unused']);
        self::assertSame(0.0, $socket['needed']);
        self::assertSame(0.0, $socket['missing']);
        self::assertSame(2, Demand::boardsFitted($rows));
        self::assertSame(0, Demand::missingRows([600 => $rows]));
        self::assertSame([9001 => 3.0, 305 => 1.0], Demand::finished($assembly, 1, self::POSITIONS[305], [701, 703]));
    }

    /** D-1387: a substitute assigned to position 701 counts for it; the stock of the listed part does not. */
    public function testAnAssignedSubstituteCountsForItsPosition(): void
    {
        $lists    = Demand::withAssigned(self::POSITIONS, [701 => 9555]);
        $assembly = new Assembly(600, 305, [], []);
        $rows     = array_column(Demand::of([$assembly], $lists, 2, [9555 => 2.0, 9002 => 5.0])[600], null, 'position');

        self::assertSame(9555, $rows[701]['thing']);
        self::assertSame(0.0, $rows[701]['missing']);
        self::assertSame(9001, $rows[700]['thing']);
        self::assertSame(self::POSITIONS, Demand::withAssigned(self::POSITIONS, []));
    }

    public function testAFallingCountReleasesWholeSurplusReservationsLargestFirst(): void
    {
        // Need 6 of 305 and 2 of 306; the box holds 1 of 305.
        $demand = [1 => [['thing' => 305, 'open' => 6.0], ['thing' => 306, 'open' => 2.0]]];
        $lines  = [
            ['line' => 1, 'thing' => 305, 'quantity' => 4.0],
            ['line' => 2, 'thing' => 305, 'quantity' => 5.0],
            ['line' => 3, 'thing' => 305, 'quantity' => 1.0],
            ['line' => 4, 'thing' => 306, 'quantity' => 2.0],
            ['line' => 5, 'thing' => 307, 'quantity' => 3.0],
        ];

        // 305: 1 + 10 − 6 = 5 spare → line 2 (5) goes, then nothing more fits; 306 has no spare; 307 is not needed at all.
        self::assertSame([2, 5], Demand::surplusReservations($demand, [305 => 1.0], $lines));
    }

    public function testABoxServesItsOrdersOldestFirstAndOwnReservationsBeforeTheBox(): void
    {
        $older = new BuildOrder(10, 'A', 1, 2, 'order_staging', 70, null, null, [new Assembly(100, 300, [], [], 0, 0)]);
        $newer = new BuildOrder(11, 'B', 1, 2, 'order_staging', 70, null, null, [new Assembly(101, 300, [], [], 0, 0)]);
        $list  = [300 => [['id' => 1, 'thing' => 305, 'count' => 3.0, 'fitting' => '']]];
        // 10 of 305 and 1 board in the box; the older order needs 6 and 2 boards, 1 of the 305 reserved for it elsewhere.
        $shares = Demand::boxShares([$newer, $older], [10 => $list, 11 => $list], [10 => [305 => 1.0]], [70 => [305 => 10.0, 300 => 1.0]]);

        self::assertSame([305 => 10.0, 300 => 1.0], $shares[10]);
        self::assertSame([305 => 5.0, 300 => 0.0], $shares[11]);
    }

    public function testShortfallNamesWhatTheBoxLacksForAFinish(): void
    {
        self::assertSame([305 => 2.0], Demand::shortfall([305 => 6.0, 300 => 1.0], [305 => 4.0, 300 => 1.0]));
        self::assertSame([], Demand::shortfall([305 => 4.0], [305 => 4.0]));
    }

    /** D-1566: J1 asks for a 12-pin strip and its part is a 12-pin strip — pieces; a long strip stays in pins (D-1398). */
    public function testACutStripCountsInPieces(): void
    {
        self::assertSame([2 => 12], Demand::withoutCutStrips([1 => 12, 2 => 12], [1 => 87436, 2 => 64765], [87436 => 12, 64765 => 40]));
        self::assertSame([1 => 12], Demand::withoutCutStrips([1 => 12], [1 => 5], []));
    }
}
