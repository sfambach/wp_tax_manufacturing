<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\Demand;

final class DemandTest extends TestCase
{
    /** His s7vrm: 10 pieces, the board itself first, «nein» never needed, an unused option needs nothing. */
    public function testNeedsBoardAndPositionsTimesPieces(): void
    {
        $positions = [305 => [
            ['id' => 700, 'thing' => 9001, 'count' => 3.0, 'fitting' => ''],
            ['id' => 701, 'thing' => 9002, 'count' => 1.0, 'fitting' => 'optional'],
            ['id' => 702, 'thing' => 9003, 'count' => 1.0, 'fitting' => 'no'],
            ['id' => 703, 'thing' => 9004, 'count' => 1.0, 'fitting' => 'optional'],
        ]];

        $rows = Demand::of([new Assembly(600, 305, 'build_open', [703, 700])], $positions, 10, [305 => 10.0, 9001 => 25.0])[600];

        self::assertSame([0, 700, 701, 703], array_column($rows, 'position'));
        self::assertSame([10.0, 30.0, 10.0, 0.0], array_column($rows, 'needed'));
        self::assertSame([0.0, 5.0, 10.0, 0.0], array_column($rows, 'missing'));
        self::assertTrue($rows[3]['unused']);
        self::assertSame(2, Demand::missingRows([600 => $rows]));
    }

    /** A part needed on two boards is counted out of the box once. */
    public function testBoxIsHandedOutOnce(): void
    {
        $positions = [1 => [['id' => 10, 'thing' => 50, 'count' => 1.0, 'fitting' => '']], 2 => [['id' => 20, 'thing' => 50, 'count' => 1.0, 'fitting' => '']]];
        $demand    = Demand::of([new Assembly(100, 1, 'build_open', []), new Assembly(200, 2, 'build_open', [])], $positions, 1, [50 => 1.0]);

        self::assertSame(0.0, $demand[100][1]['missing']);
        self::assertSame(1.0, $demand[200][1]['missing']);
    }
}
