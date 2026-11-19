<?php declare(strict_types=1);

namespace TaxmodManufacturing\Tests\Core;

use PHPUnit\Framework\TestCase;
use TaxmodManufacturing\Core\Assembly;
use TaxmodManufacturing\Core\BuildOrder;
use TaxmodManufacturing\Core\ManufacturingError;
use TaxmodManufacturing\Core\Stopwatch;
use TaxmodManufacturing\Core\Structure;

final class StopwatchTest extends TestCase
{
    private function watch(bool $fields = true): Stopwatch
    {
        return new Stopwatch(Structure::fromArray(['fields' => $fields ? ['order_worked' => 80, 'order_running' => 81] : []]));
    }

    private function order(string $step = 'order_building', float $worked = 0.0, ?string $since = null): BuildOrder
    {
        return new BuildOrder(10, 'A', 1, 4, $step, null, null, null, [new Assembly(11, 300, [], [], 0, 0)], '', null, false, '', $worked, $since);
    }

    public function testStartWritesTheMoment(): void
    {
        self::assertSame([['op' => 'set_values', 'record' => '10', 'values' => ['81' => '2026-11-19 06:00:00']]], $this->watch()->start($this->order(), '2026-11-19 06:00:00'));
    }

    public function testStopAddsTheStretchToTheSum(): void
    {
        $changes = $this->watch()->stop($this->order('order_building', 30.0, '2026-11-19 06:00:00'), '2026-11-19 06:45:30');

        self::assertSame(['80' => '75.5', '81' => ''], $changes[0]['values']);
    }

    public function testRunningStretchCountsInTheShownTime(): void
    {
        self::assertSame(90.0, Stopwatch::minutes($this->order('order_building', 60.0, '2026-11-19 06:00:00'), '2026-11-19 06:30:00'));
        self::assertSame('1:30', Stopwatch::clock(90.0));
        self::assertSame('0:00', Stopwatch::clock(0.0));
    }

    public function testNotInTestAndNotTwice(): void
    {
        foreach ([$this->order('order_test'), $this->order('order_building', 0.0, '2026-11-19 06:00:00')] as $order) {
            try {
                $this->watch()->start($order, '2026-11-19 07:00:00');
                self::fail('started');
            } catch (ManufacturingError) {
                self::assertTrue(true);
            }
        }

        $this->expectException(ManufacturingError::class);
        $this->watch()->stop($this->order(), '2026-11-19 07:00:00');
    }

    public function testStagingMayStart(): void
    {
        self::assertNotSame([], $this->watch()->start($this->order('order_staging'), '2026-11-19 07:00:00'));
    }

    public function testWithoutTheFieldsThereIsNoStopwatch(): void
    {
        self::assertFalse($this->watch(false)->exists());
        self::assertSame([], $this->watch(false)->reset($this->order('order_building', 20.0)));
    }

    public function testCompletionClearsBothAndNothingWhenEmpty(): void
    {
        self::assertSame(['80' => '', '81' => ''], $this->watch()->reset($this->order('order_building', 0.0, '2026-11-19 06:00:00'))[0]['values']);
        self::assertSame([], $this->watch()->reset($this->order()));
    }

    public function testTimeOfAllBoardsIsDividedByThem(): void
    {
        self::assertSame(15.0, Stopwatch::perBoard(60.0, true, 4));
        self::assertSame(60.0, Stopwatch::perBoard(60.0, false, 4));
        self::assertSame(60.0, Stopwatch::perBoard(60.0, true, 1));
    }

    public function testAClockSetBackCountsNothing(): void
    {
        self::assertSame(5.0, Stopwatch::minutes($this->order('order_building', 5.0, '2026-11-19 08:00:00'), '2026-11-19 07:00:00'));
    }
}
