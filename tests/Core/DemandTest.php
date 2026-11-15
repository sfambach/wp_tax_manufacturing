<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\Demand;

final class DemandTest extends TestCase
{
    private const POSITIONS = [305 => [
        ['id' => 700, 'thing' => 9001, 'count' => 3.0, 'fitting' => ''],
        ['id' => 701, 'thing' => 9002, 'count' => 1.0, 'fitting' => ''],
        ['id' => 702, 'thing' => 9003, 'count' => 1.0, 'fitting' => 'no'],
        ['id' => 703, 'thing' => 9004, 'count' => 1.0, 'fitting' => 'optional'],
    ]];

    /** His s7vrm: 10 pieces, 2 built, the coil (701) on none yet; the option 703 left out. */
    public function testFittedBoardsReduceWhatIsStillNeeded(): void
    {
        $assembly = new Assembly(600, 305, [700 => ['part' => 1, 'boards' => 2]], [703]);
        $rows     = Demand::of([$assembly], self::POSITIONS, 10, [305 => 8.0, 9001 => 20.0, 9002 => 3.0])[600];

        self::assertSame([0, 700, 701, 703], array_column($rows, 'position'));
        self::assertSame([10.0, 30.0, 10.0, 0.0], array_column($rows, 'needed'));
        self::assertSame([2, 2, 0, 0], array_column($rows, 'boards'));
        self::assertSame([8.0, 24.0, 10.0, 0.0], array_column($rows, 'open'));
        self::assertSame([0.0, 4.0, 7.0, 0.0], array_column($rows, 'missing'));
        self::assertSame(0, Demand::boardsFitted($rows));
        self::assertSame(2, Demand::missingRows([600 => $rows]));
    }

    public function testBoardsFittedIsTheSmallestCount(): void
    {
        $assembly = new Assembly(600, 305, [700 => ['part' => 1, 'boards' => 3], 701 => ['part' => 2, 'boards' => 2]], [703]);

        self::assertSame(2, Demand::boardsFitted(Demand::of([$assembly], self::POSITIONS, 10, [])[600]));
    }

    public function testConsumptionIsTheDifferenceTimesTheCount(): void
    {
        $assembly = new Assembly(600, 305, [700 => ['part' => 1, 'boards' => 2]], []);

        self::assertSame([9001 => 3.0, 9002 => 2.0, 305 => 1.0], Demand::consumption($assembly, [700 => 3, 701 => 2], self::POSITIONS[305]));
        self::assertSame([9001 => -6.0, 305 => -2.0], Demand::consumption($assembly, [700 => 0, 701 => 0], self::POSITIONS[305]));
    }

    /** D-1388 (replaces the board-record rule of D-1322): the bare board is needed and consumed as the revision record. */
    public function testBareBoardIsTheRevisionRecord(): void
    {
        $assembly = new Assembly(600, 305, [], []);
        $demand   = Demand::of([$assembly], self::POSITIONS, 2, [305 => 2.0, 200 => 2.0]);

        self::assertSame(305, $demand[600][0]['thing']);
        self::assertSame(0.0, $demand[600][0]['missing']);
        self::assertSame([9001 => 3.0, 9002 => 1.0, 305 => 1.0], Demand::consumption($assembly, [700 => 1, 701 => 1], self::POSITIONS[305]));
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
        $rows     = Demand::of([$assembly], self::POSITIONS, 10, [305 => 8.0, 9001 => 30.0])[600];
        $socket   = $rows[2];

        self::assertSame(701, $socket['position']);
        self::assertTrue($socket['unused']);
        self::assertSame(0.0, $socket['needed']);
        self::assertSame(0.0, $socket['missing']);
        self::assertSame(2, Demand::boardsFitted($rows));
        self::assertSame(0, Demand::missingRows([600 => $rows]));
        self::assertSame([9001 => 3.0, 305 => 1.0], Demand::consumption($assembly, [700 => 3], self::POSITIONS[305]));
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
}
