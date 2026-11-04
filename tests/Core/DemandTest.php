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
        $assembly = new Assembly(600, 305, [700 => ['part' => 1, 'boards' => 2]], [703, 700]);
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

    public function testBoxIsHandedOutOnce(): void
    {
        $positions = [1 => [['id' => 10, 'thing' => 50, 'count' => 1.0, 'fitting' => '']], 2 => [['id' => 20, 'thing' => 50, 'count' => 1.0, 'fitting' => '']]];
        $demand    = Demand::of([new Assembly(100, 1, [], []), new Assembly(200, 2, [], [])], $positions, 1, [50 => 1.0]);

        self::assertSame(0.0, $demand[100][1]['missing']);
        self::assertSame(1.0, $demand[200][1]['missing']);
    }
}
