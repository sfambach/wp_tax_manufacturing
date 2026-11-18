<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\Positions;

/** D-1433: the status of each board, derived from its lot; the order's from its boards. */
final class PositionsTest extends TestCase
{
    private function order(string $step, int $count, int $done): BuildOrder
    {
        return new BuildOrder(500, 'A', 100, $count, $step, 900, '2026-11-18', null, [new Assembly(600, 305, [], [], 0, $done)]);
    }

    public function testEachBoardCountsInOneStatus(): void
    {
        $lots = [1 => ['state' => 'untested'], 2 => ['state' => 'failed'], 3 => ['state' => 'passed'], 4 => ['state' => 'scrapped']];

        self::assertSame(['building' => 2, 'test' => 1, 'rework' => 1, 'done' => 1, 'scrapped' => 1], Positions::counts($this->order('order_test', 6, 4), $lots));
    }

    public function testTheOrderIsItsEarliestOpenStatus(): void
    {
        self::assertSame('order_building', Positions::orderStep($this->order('order_test', 3, 2), [1 => ['state' => 'passed'], 2 => ['state' => 'passed']]));
        self::assertSame('order_test', Positions::orderStep($this->order('order_done', 2, 2), [1 => ['state' => 'passed'], 2 => ['state' => 'failed']]));
        self::assertSame('order_done', Positions::orderStep($this->order('order_test', 2, 2), [1 => ['state' => 'passed'], 2 => ['state' => 'scrapped']]));
    }

    public function testBeforeBuildingAndWhenCancelledTheStoredStepStands(): void
    {
        self::assertSame('order_staging', Positions::orderStep($this->order('order_staging', 2, 0), []));
        self::assertSame('order_cancelled', Positions::orderStep($this->order('order_cancelled', 2, 2), [1 => ['state' => 'untested']]));
    }

    public function testBoardsWithoutALotKeepWhatTheOrderSaid(): void
    {
        self::assertSame('order_done', Positions::orderStep($this->order('order_done', 2, 2), []));
        self::assertSame('order_test', Positions::orderStep($this->order('order_test', 2, 2), []));
    }

    public function testAStoredFailedOrderStandsAsStored(): void
    {
        self::assertSame('order_failed', Positions::orderStep($this->order('order_failed', 1, 1), [1 => ['state' => 'passed']]));
    }
}
